<?php
/**
 * Application audit trail — who did what, when, and from where.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function audit_current_actor(): ?array
{
    return function_exists('current_user') ? current_user() : null;
}

function audit_normalize_value(mixed $value): ?string
{
    if ($value === null) {
        return null;
    }
    if (is_bool($value)) {
        return $value ? '1' : '0';
    }
    return (string) $value;
}

function audit_format_field(string $field): string
{
    return str_replace('_', ' ', $field);
}

function audit_entity_label(string $entityType): string
{
    return match ($entityType) {
        'emergency_report' => 'Emergency report',
        'dispatch_assignment' => 'Dispatch assignment',
        'emergency_type' => 'Emergency type',
        'dispatch_unit' => 'Dispatch unit',
        'hospital' => 'Hospital',
        'user' => 'User account',
        'responder' => 'Responder',
        'session' => 'Session',
        default => ucwords(str_replace('_', ' ', $entityType)),
    };
}

function audit_event_label(string $eventType): string
{
    return match ($eventType) {
        'created' => 'Created',
        'updated' => 'Updated',
        'deleted' => 'Deleted',
        'status_changed' => 'Status changed',
        'login' => 'Logged in',
        'logout' => 'Logged out',
        'verified' => 'Verified',
        'rejected' => 'Rejected',
        'resolved' => 'Resolved',
        'dispatched' => 'Dispatched',
        'cancelled' => 'Cancelled',
        default => ucfirst(str_replace('_', ' ', $eventType)),
    };
}

/**
 * @param array<string, mixed>|null $actor
 */
function audit_log(
    string $eventType,
    string $entityType,
    ?int $entityId = null,
    ?string $entityLabel = null,
    ?string $summary = null,
    ?string $fieldName = null,
    mixed $oldValue = null,
    mixed $newValue = null,
    ?array $actor = null,
): void {
    try {
        if ($actor === null) {
            $actor = audit_current_actor();
        }

        $actorUserId = $actor ? (int) ($actor['id'] ?? 0) : null;
        $actorName = $actor ? (string) ($actor['name'] ?? 'Unknown') : 'System';
        $actorRole = $actor ? (string) ($actor['role'] ?? '') : 'system';

        if ($summary === null) {
            $target = $entityLabel ?: (audit_entity_label($entityType) . ($entityId ? " #{$entityId}" : ''));
            $summary = audit_event_label($eventType) . ': ' . $target;
            if ($fieldName !== null && ($oldValue !== null || $newValue !== null)) {
                $summary .= ' — ' . audit_format_field($fieldName);
                if ($oldValue !== null && $newValue !== null) {
                    $summary .= ': ' . audit_normalize_value($oldValue) . ' → ' . audit_normalize_value($newValue);
                } elseif ($newValue !== null) {
                    $summary .= ' set to ' . audit_normalize_value($newValue);
                }
            }
        }

        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        $path = $_SERVER['REQUEST_URI'] ?? null;

        $stmt = db()->prepare('
            INSERT INTO Audit_Log
                (event_type, entity_type, entity_id, entity_label, field_name, old_value, new_value, summary,
                 actor_user_id, actor_name, actor_role, ip_address, request_path)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $eventType,
            $entityType,
            $entityId,
            $entityLabel,
            $fieldName,
            audit_normalize_value($oldValue),
            audit_normalize_value($newValue),
            mb_substr($summary, 0, 500),
            $actorUserId ?: null,
            $actorName,
            $actorRole ?: 'system',
            $ip,
            $path !== null ? mb_substr($path, 0, 255) : null,
        ]);
    } catch (Throwable) {
        // Audit must never break primary operations.
    }
}

/**
 * @param array<string, array{old?: mixed, new?: mixed}> $changes
 * @param array<string, mixed>|null $actor
 */
function audit_log_changes(
    string $eventType,
    string $entityType,
    int $entityId,
    ?string $entityLabel,
    array $changes,
    ?array $actor = null,
): void {
    foreach ($changes as $field => $pair) {
        $old = $pair['old'] ?? null;
        $new = $pair['new'] ?? null;
        if ((string) $old === (string) $new) {
            continue;
        }
        $resolvedEvent = $eventType;
        if ($eventType === 'updated' && str_contains((string) $field, 'status')) {
            $resolvedEvent = 'status_changed';
        }
        audit_log(
            $resolvedEvent,
            $entityType,
            $entityId,
            $entityLabel,
            null,
            (string) $field,
            $old,
            $new,
            $actor,
        );
    }
}

function audit_fetch_row(PDO $pdo, string $table, int $id): ?array
{
    $allowed = [
        'Emergency_Report',
        'Emergency_Type',
        'Dispatch_Unit',
        'Hospital',
        'Users',
        'Responder',
        'Dispatch_Assignment',
    ];
    if (!in_array($table, $allowed, true)) {
        return null;
    }
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function audit_compare_rows(array $before, array $after, array $fields): array
{
    $changes = [];
    foreach ($fields as $field) {
        $old = $before[$field] ?? null;
        $new = $after[$field] ?? null;
        if ((string) $old !== (string) $new) {
            $changes[$field] = ['old' => $old, 'new' => $new];
        }
    }
    return $changes;
}

function audit_report_label(array $row): string
{
    return (string) ($row['title'] ?? ('Report #' . ($row['id'] ?? '?')));
}

function audit_responder_label(PDO $pdo, int $responderId): string
{
    $stmt = $pdo->prepare('
        SELECT u.name, r.badge_no
        FROM Responder r
        INNER JOIN Users u ON u.id = r.user_id
        WHERE r.id = ?
    ');
    $stmt->execute([$responderId]);
    $row = $stmt->fetch();
    if (!$row) {
        return 'Responder #' . $responderId;
    }
    return $row['name'] . ' (' . $row['badge_no'] . ')';
}
