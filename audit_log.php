<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audit.php';

require_login('admin');
$pdo = db();

$filters = [
    'entity_type' => trim((string) ($_GET['entity_type'] ?? '')),
    'event_type' => trim((string) ($_GET['event_type'] ?? '')),
    'actor' => trim((string) ($_GET['actor'] ?? '')),
    'q' => trim((string) ($_GET['q'] ?? '')),
];

$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 50;

$where = ['1=1'];
$params = [];

$entityTypes = [
    'emergency_report' => 'Emergency reports',
    'dispatch_assignment' => 'Dispatch assignments',
    'emergency_type' => 'Emergency types',
    'dispatch_unit' => 'Dispatch units',
    'hospital' => 'Hospitals',
    'responder' => 'Responders',
    'user' => 'User accounts',
    'session' => 'Logins & logouts',
];

$eventTypes = [
    'created' => 'Created',
    'updated' => 'Updated',
    'deleted' => 'Deleted',
    'status_changed' => 'Status changed',
    'verified' => 'Verified',
    'rejected' => 'Rejected',
    'resolved' => 'Resolved',
    'dispatched' => 'Dispatched',
    'cancelled' => 'Cancelled',
    'login' => 'Login',
    'logout' => 'Logout',
];

if ($filters['entity_type'] !== '' && isset($entityTypes[$filters['entity_type']])) {
    $where[] = 'al.entity_type = ?';
    $params[] = $filters['entity_type'];
}
if ($filters['event_type'] !== '' && isset($eventTypes[$filters['event_type']])) {
    $where[] = 'al.event_type = ?';
    $params[] = $filters['event_type'];
}
if ($filters['actor'] !== '') {
    $where[] = '(al.actor_name LIKE ? OR al.actor_role LIKE ?)';
    $params[] = '%' . $filters['actor'] . '%';
    $params[] = '%' . $filters['actor'] . '%';
}
if ($filters['q'] !== '') {
    $where[] = '(al.summary LIKE ? OR al.entity_label LIKE ? OR CAST(al.entity_id AS CHAR) LIKE ?)';
    $like = '%' . $filters['q'] . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$whereSql = implode(' AND ', $where);

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM Audit_Log al WHERE {$whereSql}");
$countStmt->execute($params);
$total = (int) $countStmt->fetchColumn();
$totalPages = max(1, (int) ceil($total / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$listStmt = $pdo->prepare("
    SELECT al.*
    FROM Audit_Log al
    WHERE {$whereSql}
    ORDER BY al.changed_at DESC, al.id DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$listStmt->execute($params);
$entries = $listStmt->fetchAll();

$summary = [
    'total' => (int) $pdo->query('SELECT COUNT(*) FROM Audit_Log')->fetchColumn(),
    'today' => (int) $pdo->query('SELECT COUNT(*) FROM Audit_Log WHERE DATE(changed_at) = CURDATE()')->fetchColumn(),
    'actors' => (int) $pdo->query('SELECT COUNT(DISTINCT actor_user_id) FROM Audit_Log WHERE actor_user_id IS NOT NULL')->fetchColumn(),
    'logins' => (int) $pdo->query('SELECT COUNT(*) FROM Audit_Log WHERE event_type IN ("login", "logout")')->fetchColumn(),
];

$queryBase = array_filter([
    'entity_type' => $filters['entity_type'] !== '' ? $filters['entity_type'] : null,
    'event_type' => $filters['event_type'] !== '' ? $filters['event_type'] : null,
    'actor' => $filters['actor'] !== '' ? $filters['actor'] : null,
    'q' => $filters['q'] !== '' ? $filters['q'] : null,
]);

$pageUrl = function (int $targetPage) use ($queryBase): string {
    $query = $queryBase;
    if ($targetPage > 1) {
        $query['page'] = (string) $targetPage;
    }
    $qs = http_build_query($query);
    return url_path('admin/audit_log.php' . ($qs !== '' ? '?' . $qs : ''));
};

$eventBadge = static function (string $eventType): string {
    return match ($eventType) {
        'created', 'login' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'updated', 'status_changed', 'verified', 'dispatched' => 'bg-sky-100 text-sky-800 border-sky-200',
        'resolved', 'logout' => 'bg-indigo-100 text-indigo-800 border-indigo-200',
        'rejected', 'deleted', 'cancelled' => 'bg-rose-100 text-rose-800 border-rose-200',
        default => 'bg-slate-100 text-slate-700 border-slate-200',
    };
};

$roleBadge = static function (string $role): string {
    return match ($role) {
        'admin' => 'bg-red-100 text-red-800 border-red-200',
        'responder' => 'bg-orange-100 text-orange-800 border-orange-200',
        'user' => 'bg-blue-100 text-blue-800 border-blue-200',
        default => 'bg-slate-100 text-slate-700 border-slate-200',
    };
};

page_header('Audit Log', 'audit', false);
?>
<section class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase text-red-600">Admin Only</p>
            <h1 class="mt-1 text-3xl font-black text-slate-950">Platform Audit Log</h1>
            <p class="mt-2 max-w-3xl text-sm text-slate-600">
                Complete activity trail for the emergency platform — every create, update, dispatch, login, and status change with who performed it and when.
            </p>
        </div>
        <a class="btn-secondary" href="<?= h(url_path('admin/index.php')) ?>">Back to Dashboard</a>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <div class="panel p-4">
            <span class="text-xs font-bold uppercase text-slate-500">Total Events</span>
            <strong class="mt-2 block text-3xl text-slate-950"><?= $summary['total'] ?></strong>
        </div>
        <div class="panel p-4">
            <span class="text-xs font-bold uppercase text-slate-500">Today</span>
            <strong class="mt-2 block text-3xl text-sky-600"><?= $summary['today'] ?></strong>
        </div>
        <div class="panel p-4">
            <span class="text-xs font-bold uppercase text-slate-500">Unique Users</span>
            <strong class="mt-2 block text-3xl text-indigo-600"><?= $summary['actors'] ?></strong>
        </div>
        <div class="panel p-4">
            <span class="text-xs font-bold uppercase text-slate-500">Auth Events</span>
            <strong class="mt-2 block text-3xl text-emerald-600"><?= $summary['logins'] ?></strong>
        </div>
    </div>

    <form method="get" class="panel grid gap-4 p-4 md:grid-cols-2 xl:grid-cols-5">
        <div>
            <label class="field-label" for="entity_type">Category</label>
            <select class="field-input" id="entity_type" name="entity_type">
                <option value="">All categories</option>
                <?php foreach ($entityTypes as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $filters['entity_type'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label" for="event_type">Action</label>
            <select class="field-input" id="event_type" name="event_type">
                <option value="">All actions</option>
                <?php foreach ($eventTypes as $value => $label): ?>
                    <option value="<?= h($value) ?>" <?= $filters['event_type'] === $value ? 'selected' : '' ?>><?= h($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="field-label" for="actor">Who</label>
            <input class="field-input" type="text" id="actor" name="actor" value="<?= h($filters['actor']) ?>" placeholder="Name or role">
        </div>
        <div>
            <label class="field-label" for="q">Search</label>
            <input class="field-input" type="text" id="q" name="q" value="<?= h($filters['q']) ?>" placeholder="Summary, label, or ID">
        </div>
        <div class="flex items-end gap-2">
            <button class="btn-primary flex-1" type="submit">Filter</button>
            <a class="btn-secondary" href="<?= h(url_path('admin/audit_log.php')) ?>">Reset</a>
        </div>
    </form>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <p class="text-sm text-slate-600">
            Showing <?= $total === 0 ? 0 : ($offset + 1) ?>–<?= min($offset + $perPage, $total) ?> of <?= $total ?> events
        </p>
        <?php if ($totalPages > 1): ?>
            <div class="flex flex-wrap gap-2">
                <?php if ($page > 1): ?>
                    <a class="btn-secondary" href="<?= h($pageUrl($page - 1)) ?>">Previous</a>
                <?php endif; ?>
                <span class="inline-flex items-center px-3 text-sm text-slate-600">Page <?= $page ?> of <?= $totalPages ?></span>
                <?php if ($page < $totalPages): ?>
                    <a class="btn-secondary" href="<?= h($pageUrl($page + 1)) ?>">Next</a>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>When</th>
                    <th>Who</th>
                    <th>Action</th>
                    <th>What</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($entries === []): ?>
                    <tr>
                        <td colspan="5" class="py-8 text-center text-slate-500">No audit events yet. Activity will appear here as users interact with the platform.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($entries as $row): ?>
                        <tr>
                            <td class="whitespace-nowrap text-sm">
                                <strong class="block text-slate-950"><?= h(date('M j, Y', strtotime((string) $row['changed_at']))) ?></strong>
                                <span class="text-slate-500"><?= h(date('g:i:s A', strtotime((string) $row['changed_at']))) ?></span>
                            </td>
                            <td>
                                <strong class="block text-slate-950"><?= h($row['actor_name']) ?></strong>
                                <span class="badge <?= h($roleBadge((string) $row['actor_role'])) ?>"><?= h($row['actor_role']) ?></span>
                                <?php if ($row['ip_address']): ?>
                                    <span class="mt-1 block text-xs text-slate-500"><?= h($row['ip_address']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge <?= h($eventBadge((string) $row['event_type'])) ?>"><?= h(audit_event_label((string) $row['event_type'])) ?></span>
                                <span class="mt-1 block text-xs text-slate-500"><?= h(audit_entity_label((string) $row['entity_type'])) ?></span>
                            </td>
                            <td>
                                <strong class="block text-slate-950"><?= h($row['entity_label'] ?: ('#' . ($row['entity_id'] ?? '—'))) ?></strong>
                                <?php if ($row['entity_id']): ?>
                                    <span class="text-sm text-slate-500">ID #<?= (int) $row['entity_id'] ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <p class="text-sm text-slate-800"><?= h($row['summary']) ?></p>
                                <?php if ($row['old_value'] !== null || $row['new_value'] !== null): ?>
                                    <div class="mt-1 flex flex-wrap items-center gap-1">
                                        <?php if ($row['old_value'] !== null && $row['new_value'] !== null): ?>
                                            <span class="badge <?= h(status_badge_class((string) $row['old_value'])) ?>"><?= h(str_replace('_', ' ', (string) $row['old_value'])) ?></span>
                                            <span class="text-slate-400">→</span>
                                            <span class="badge <?= h(status_badge_class((string) $row['new_value'])) ?>"><?= h(str_replace('_', ' ', (string) $row['new_value'])) ?></span>
                                        <?php elseif ($row['new_value'] !== null): ?>
                                            <span class="badge <?= h(status_badge_class((string) $row['new_value'])) ?>"><?= h(str_replace('_', ' ', (string) $row['new_value'])) ?></span>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($row['request_path']): ?>
                                    <span class="mt-1 block truncate text-xs text-slate-400" title="<?= h($row['request_path']) ?>"><?= h($row['request_path']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_footer(); ?>
