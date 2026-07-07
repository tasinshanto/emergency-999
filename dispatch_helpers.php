<?php
/**
 * Dispatch helpers — geo distance, nearest resources, auto-assign, and self-dispatch rules.
 *
 * Range constants live in config.php (DISPATCH_RANGE_KM). Default 12 km matches a realistic
 * urban first-response radius in dense cities (roughly 15–25 minutes by emergency vehicle).
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/audit.php';

/** Write a debug-session NDJSON line (debug mode only). */
function dispatch_debug_log(string $location, string $message, array $data, string $hypothesisId): void
{
    // #region agent log
    $logPath = dirname(__DIR__) . '/debug-76f46e.log';
    $payload = [
        'sessionId' => '76f46e',
        'hypothesisId' => $hypothesisId,
        'location' => $location,
        'message' => $message,
        'data' => $data,
        'timestamp' => (int) round(microtime(true) * 1000),
    ];
    @file_put_contents($logPath, json_encode($payload, JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    // #endregion
}

/** Haversine distance in kilometres between two WGS84 points. */
function haversine_km(float $lat1, float $lng1, float $lat2, float $lng2): float
{
    $earthRadius = 6371.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLng = deg2rad($lng2 - $lng1);
    $a = sin($dLat / 2) ** 2
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;
    return $earthRadius * 2 * atan2(sqrt($a), sqrt(1 - $a));
}

/** SQL fragment for Haversine distance (km) from bind params lat/lng to table columns. */
function sql_distance_km(string $latCol, string $lngCol): string
{
    return "(6371 * ACOS(LEAST(1, COS(RADIANS(?)) * COS(RADIANS($latCol))
        * COS(RADIANS($lngCol) - RADIANS(?))
        + SIN(RADIANS(?)) * SIN(RADIANS($latCol)))))";
}

/** Map emergency type name/id to dispatch unit types that should respond. */
function unit_types_for_emergency(string $typeName): array
{
    $name = strtolower($typeName);
    if (str_contains($name, 'fire')) {
        return ['fire'];
    }
    if (str_contains($name, 'medical')) {
        return ['ambulance'];
    }
    if (str_contains($name, 'police')) {
        return ['police'];
    }
    if (str_contains($name, 'rescue')) {
        return ['rescue', 'fire'];
    }
    return ['fire', 'ambulance', 'police', 'rescue', 'other'];
}

/**
 * Load report row with type name; returns null if missing or terminal status.
 *
 * @return array<string, mixed>|null
 */
function fetch_dispatchable_report(PDO $pdo, int $reportId): ?array
{
    $stmt = $pdo->prepare('
        SELECT er.*, et.name AS type_name
        FROM Emergency_Report er
        INNER JOIN Emergency_Type et ON et.id = er.emergency_type_id
        WHERE er.id = ?
    ');
    $stmt->execute([$reportId]);
    $row = $stmt->fetch();
    if (!$row || in_array($row['status'], ['resolved', 'rejected'], true)) {
        return null;
    }
    return $row;
}

/** Active assignment on a report (not cancelled/completed), if any. */
function fetch_active_assignment(PDO $pdo, int $reportId): ?array
{
    $stmt = $pdo->prepare('
        SELECT da.*
        FROM Dispatch_Assignment da
        WHERE da.emergency_report_id = ?
          AND da.assignment_status IN ("assigned", "accepted", "arrived")
        ORDER BY da.id DESC
        LIMIT 1
    ');
    $stmt->execute([$reportId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/**
 * Nearest dispatch units and hospitals (organizations) for a report location.
 *
 * @return array{units: list<array>, hospitals: list<array>, responders: list<array>}
 */
function nearest_dispatch_suggestions(PDO $pdo, float $lat, float $lng, string|array $unitTypesOrTypeName, int $limit = 8): array
{
    $rangeKm = (float) DISPATCH_RANGE_KM;
    if (is_array($unitTypesOrTypeName)) {
        $unitTypes = $unitTypesOrTypeName;
    } elseif (str_contains($unitTypesOrTypeName, ',')) {
        $unitTypes = array_filter(explode(',', $unitTypesOrTypeName));
    } else {
        $unitTypes = unit_types_for_emergency($unitTypesOrTypeName);
    }
    if (empty($unitTypes)) {
        $unitTypes = ['other'];
    }
    $placeholders = implode(',', array_fill(0, count($unitTypes), '?'));
    $distSql = sql_distance_km('du.latitude', 'du.longitude');

    $params = [$lat, $lng, $lat];
    $typeFilter = " AND du.unit_type IN ($placeholders)";
    $params = array_merge($params, $unitTypes);

    $stmt = $pdo->prepare("
        SELECT du.id, du.unit_name, du.unit_type, du.status, du.base_address,
               du.latitude, du.longitude, $distSql AS distance_km
        FROM Dispatch_Unit du
        WHERE 1=1 $typeFilter
        ORDER BY distance_km ASC
        LIMIT " . (int) $limit
    );
    $stmt->execute($params);
    $units = $stmt->fetchAll();
    foreach ($units as &$u) {
        $u['distance_km'] = round((float) $u['distance_km'], 2);
        $u['in_range'] = $u['distance_km'] <= $rangeKm;
        $u['kind'] = 'unit';
    }
    unset($u);

    $distH = sql_distance_km('h.latitude', 'h.longitude');
    $stmt = $pdo->prepare("
        SELECT h.id, h.name, h.status, h.address, h.latitude, h.longitude, $distH AS distance_km
        FROM Hospital h
        ORDER BY distance_km ASC
        LIMIT " . (int) min(5, $limit)
    );
    $stmt->execute([$lat, $lng, $lat]);
    $hospitals = $stmt->fetchAll();
    foreach ($hospitals as &$h) {
        $h['distance_km'] = round((float) $h['distance_km'], 2);
        $h['in_range'] = $h['distance_km'] <= $rangeKm;
        $h['kind'] = 'hospital';
    }
    unset($h);

    $responders = [];
    if ($units) {
        $unitIds = array_column($units, 'id');
        $in = implode(',', array_fill(0, count($unitIds), '?'));
        $stmt = $pdo->prepare("
            SELECT r.id, r.badge_no, r.availability_status, r.dispatch_unit_id,
                   u.name AS responder_name, du.unit_name
            FROM Responder r
            INNER JOIN Users u ON u.id = r.user_id
            LEFT JOIN Dispatch_Unit du ON du.id = r.dispatch_unit_id
            WHERE r.dispatch_unit_id IN ($in)
            ORDER BY FIELD(r.availability_status, 'available', 'assigned', 'on_scene', 'offline'), u.name
        ");
        $stmt->execute($unitIds);
        $responders = $stmt->fetchAll();
    }

    return ['units' => $units, 'hospitals' => $hospitals, 'responders' => $responders, 'range_km' => $rangeKm];
}

/** True when no available matching unit exists within dispatch range. */
function all_nearby_units_busy(PDO $pdo, float $lat, float $lng, string $typeName): bool
{
    $suggestions = nearest_dispatch_suggestions($pdo, $lat, $lng, $typeName, 20);
    foreach ($suggestions['units'] as $unit) {
        if ($unit['in_range'] && $unit['status'] === 'available') {
            return false;
        }
    }
    return true;
}

/** True when every matching unit type globally is busy or offline. */
function all_matching_units_busy_globally(PDO $pdo, string $typeName): bool
{
    $unitTypes = unit_types_for_emergency($typeName);
    $placeholders = implode(',', array_fill(0, count($unitTypes), '?'));
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM Dispatch_Unit
        WHERE unit_type IN ($placeholders) AND status = 'available'
    ");
    $stmt->execute($unitTypes);
    return (int) $stmt->fetchColumn() === 0;
}

/**
 * Create dispatch assignment and update report/unit/responder statuses.
 *
 * @param int|null $assignedBy User id of admin, or null for self-dispatch.
 */
function create_dispatch_assignment(
    PDO $pdo,
    int $reportId,
    int $unitId,
    int $responderId,
    ?int $assignedBy,
    string $instructions = '',
    ?array $actor = null,
): void {
    // Wrap in a transaction so SELECT FOR UPDATE row locks are held until COMMIT,
    // preventing concurrent dispatchers from double-booking the same unit/responder.
    $ownTransaction = !$pdo->inTransaction();
    if ($ownTransaction) {
        $pdo->beginTransaction();
    }

    try {
        $reportBefore = fetch_dispatchable_report($pdo, $reportId) ?: audit_fetch_row($pdo, 'Emergency_Report', $reportId);
        $reportLabel = $reportBefore ? audit_report_label($reportBefore) : ('Report #' . $reportId);

        $stmt = $pdo->prepare('SELECT unit_type FROM Dispatch_Unit WHERE id = ?');
        $stmt->execute([$unitId]);
        $newUnitType = $stmt->fetchColumn();

        $cancelStmt = $pdo->prepare('
            SELECT da.id, da.assignment_status
            FROM Dispatch_Assignment da
            INNER JOIN Dispatch_Unit du ON du.id = da.dispatch_unit_id
            WHERE da.emergency_report_id = ?
              AND du.unit_type = ?
              AND da.assignment_status IN ("assigned", "accepted", "arrived")
        ');
        $cancelStmt->execute([$reportId, $newUnitType]);
        $toCancel = $cancelStmt->fetchAll();

        // Cancel any existing active assignments of the same unit type on this report first.
        // The AFTER UPDATE trigger auto-frees old unit/responder resources.
        if ($toCancel) {
            $ids = array_column($toCancel, 'id');
            $in = implode(',', array_fill(0, count($ids), '?'));
            $pdo->prepare("
                UPDATE Dispatch_Assignment SET assignment_status = 'cancelled'
                WHERE id IN ($in)
            ")->execute($ids);
        }

        foreach ($toCancel as $cancelled) {
            audit_log(
                'cancelled',
                'dispatch_assignment',
                (int) $cancelled['id'],
                $reportLabel,
                null,
                'assignment_status',
                $cancelled['assignment_status'],
                'cancelled',
                $actor,
            );
        }

        // ── Pessimistic locking: acquire exclusive row locks on the unit
        //    and responder before inserting to prevent race conditions. ──
        $pdo->prepare('
            SELECT id, status, capacity, active_assignments_count
            FROM Dispatch_Unit WHERE id = ? FOR UPDATE
        ')->execute([$unitId]);

        $pdo->prepare('
            SELECT id, availability_status FROM Responder WHERE id = ? FOR UPDATE
        ')->execute([$responderId]);
        // ────────────────────────────────────────────────────────────────

        //   • Increment Dispatch_Unit.active_assignments_count
        //   • Set Dispatch_Unit.status = 'busy' when count >= capacity
        //   • Set Responder.availability_status = 'assigned'
        $pdo->prepare('
            INSERT INTO Dispatch_Assignment
                (emergency_report_id, dispatch_unit_id, responder_id, assigned_by, assignment_status, instructions)
            VALUES (?, ?, ?, ?, "assigned", ?)
        ')->execute([$reportId, $unitId, $responderId, $assignedBy, $instructions]);
        $assignmentId = (int) $pdo->lastInsertId();

        $oldReportStatus = $reportBefore['status'] ?? null;
        $pdo->prepare('
            UPDATE Emergency_Report SET status = "assigned", verified_by = COALESCE(verified_by, ?)
            WHERE id = ?
        ')->execute([$assignedBy, $reportId]);

        audit_log(
            'dispatched',
            'dispatch_assignment',
            $assignmentId,
            $reportLabel,
            'Dispatched ' . audit_responder_label($pdo, $responderId) . ' to ' . $reportLabel,
            'assignment_status',
            null,
            'assigned',
            $actor,
        );
        if ($oldReportStatus !== null && $oldReportStatus !== 'assigned') {
            audit_log(
                'status_changed',
                'emergency_report',
                $reportId,
                $reportLabel,
                null,
                'status',
                $oldReportStatus,
                'assigned',
                $actor,
            );
        }

        if ($ownTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($ownTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Release resources tied to active assignments on a report.
 *
 * Sets any open assignment to 'cancelled'.  The AFTER UPDATE trigger on
 * Dispatch_Assignment then automatically handles:
 *   • Decrement of Dispatch_Unit.active_assignments_count
 *   • Restore Dispatch_Unit.status to 'available' when count < capacity
 *   • Restore Responder.availability_status to 'available'
 *
 * PHP no longer needs to touch Dispatch_Unit or Responder directly.
 */
function release_assignment_resources_for_report(PDO $pdo, int $reportId, ?array $actor = null): void
{
    $reportRow = audit_fetch_row($pdo, 'Emergency_Report', $reportId);
    $reportLabel = $reportRow ? audit_report_label($reportRow) : ('Report #' . $reportId);

    $stmt = $pdo->prepare('
        SELECT id, assignment_status
        FROM Dispatch_Assignment
        WHERE emergency_report_id = ?
          AND assignment_status IN ("assigned", "accepted", "arrived")
    ');
    $stmt->execute([$reportId]);
    $active = $stmt->fetchAll();

    $pdo->prepare('
        UPDATE Dispatch_Assignment
        SET assignment_status = "cancelled"
        WHERE emergency_report_id = ?
          AND assignment_status IN ("assigned", "accepted", "arrived")
    ')->execute([$reportId]);

    foreach ($active as $row) {
        audit_log(
            'cancelled',
            'dispatch_assignment',
            (int) $row['id'],
            $reportLabel,
            null,
            'assignment_status',
            $row['assignment_status'],
            'cancelled',
            $actor,
        );
    }
}

/**
 * Pick nearest available unit + responder in range and assign (auto-dispatch on verify).
 *
 * @return array{assigned: bool, unit_id?: int, responder_id?: int, distance_km?: float, reason?: string}
 */
function try_auto_dispatch(PDO $pdo, int $reportId, ?int $adminUserId, ?array $actor = null): array
{
    $report = fetch_dispatchable_report($pdo, $reportId);
    if (!$report || $report['status'] !== 'verified') {
        dispatch_debug_log('dispatch_helpers.php:try_auto_dispatch', 'skip status', ['reportId' => $reportId, 'status' => $report['status'] ?? null], 'H1');
        return ['assigned' => false, 'reason' => 'not_verified'];
    }
    if (fetch_active_assignment($pdo, $reportId)) {
        dispatch_debug_log('dispatch_helpers.php:try_auto_dispatch', 'skip has assignment', ['reportId' => $reportId], 'H2');
        return ['assigned' => false, 'reason' => 'already_assigned'];
    }

    $lat = (float) $report['latitude'];
    $lng = (float) $report['longitude'];
    $rangeKm = (float) DISPATCH_RANGE_KM;
    $requestedTypes = !empty($report['requested_unit_types']) ? $report['requested_unit_types'] : (string) $report['type_name'];
    $suggestions = nearest_dispatch_suggestions($pdo, $lat, $lng, $requestedTypes, 15);

    foreach ($suggestions['units'] as $unit) {
        if (!$unit['in_range'] || $unit['status'] !== 'available') {
            continue;
        }
        $unitId = (int) $unit['id'];
        $stmt = $pdo->prepare('
            SELECT r.id FROM Responder r
            WHERE r.dispatch_unit_id = ? AND r.availability_status = "available"
            ORDER BY r.id ASC LIMIT 1
        ');
        $stmt->execute([$unitId]);
        $responderId = (int) ($stmt->fetchColumn() ?: 0);
        if ($responderId <= 0) {
            continue;
        }

        create_dispatch_assignment(
            $pdo,
            $reportId,
            $unitId,
            $responderId,
            $adminUserId,
            'Auto-dispatched: nearest available unit within ' . $rangeKm . ' km.',
            $actor,
        );

        dispatch_debug_log('dispatch_helpers.php:try_auto_dispatch', 'auto assigned', [
            'reportId' => $reportId,
            'unitId' => $unitId,
            'responderId' => $responderId,
            'distance_km' => $unit['distance_km'],
        ], 'H3');

        return [
            'assigned' => true,
            'unit_id' => $unitId,
            'responder_id' => $responderId,
            'distance_km' => $unit['distance_km'],
        ];
    }

    dispatch_debug_log('dispatch_helpers.php:try_auto_dispatch', 'no unit in range', ['reportId' => $reportId, 'rangeKm' => $rangeKm], 'H4');
    return ['assigned' => false, 'reason' => 'no_available_in_range'];
}

/**
 * Whether a responder may self-assign to an active report from the live map.
 *
 * @return array{allowed: bool, within_range: bool, all_busy_override: bool, reason?: string, distance_km?: float}
 */
function evaluate_responder_self_dispatch(PDO $pdo, array $responder, array $report): array
{
    $responderId = (int) $responder['id'];
    if ($responder['availability_status'] !== 'available') {
        return ['allowed' => false, 'within_range' => false, 'all_busy_override' => false, 'reason' => 'responder_busy'];
    }
    if (!in_array($report['status'], ['verified', 'assigned', 'dispatched', 'in_progress'], true)) {
        return ['allowed' => false, 'within_range' => false, 'all_busy_override' => false, 'reason' => 'invalid_report_status'];
    }

    $active = fetch_active_assignment($pdo, (int) $report['id']);
    if ($active) {
        if ((int) ($active['responder_id'] ?? 0) === $responderId) {
            return ['allowed' => false, 'within_range' => false, 'all_busy_override' => false, 'reason' => 'already_assigned_to_you'];
        }
        if ($active['responder_id']) {
            return ['allowed' => false, 'within_range' => false, 'all_busy_override' => false, 'reason' => 'assigned_to_other'];
        }
    }

    if (!(int) ($responder['dispatch_unit_id'] ?? 0)) {
        return ['allowed' => false, 'within_range' => false, 'all_busy_override' => false, 'reason' => 'no_unit'];
    }

    $baseLat = (float) ($responder['base_lat'] ?? 23.8103);
    $baseLng = (float) ($responder['base_lng'] ?? 90.4125);
    $dist = haversine_km($baseLat, $baseLng, (float) $report['latitude'], (float) $report['longitude']);
    $withinRange = $dist <= (float) DISPATCH_RANGE_KM;

    return [
        'allowed' => true,
        'within_range' => $withinRange,
        'all_busy_override' => false,
        'distance_km' => round($dist, 2),
    ];
}
