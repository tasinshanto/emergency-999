<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

$user = require_login('admin');
$pdo = db();

$count = function (string $sql, array $params = []) use ($pdo): int {
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return (int) $stmt->fetchColumn();
};

$summary = [
    'pending' => $count('SELECT COUNT(*) FROM Emergency_Report WHERE status = "pending"'),
    'active' => $count('SELECT COUNT(*) FROM Emergency_Report WHERE status IN ("verified", "assigned", "dispatched", "in_progress")'),
    'resolved' => $count('SELECT COUNT(*) FROM Emergency_Report WHERE status = "resolved"'),
    'available_units' => $count('SELECT COUNT(*) FROM Dispatch_Unit WHERE status = "available"'),
    'available_responders' => $count('SELECT COUNT(*) FROM Responder WHERE availability_status = "available"'),
    'open_assignments' => $count('SELECT COUNT(*) FROM Dispatch_Assignment WHERE assignment_status IN ("assigned", "accepted", "arrived")'),
];

$recent = $pdo->query('
    SELECT er.id, er.title, er.address, er.severity, er.status, er.created_at, et.name AS type_name
    FROM Emergency_Report er
    INNER JOIN Emergency_Type et ON et.id = er.emergency_type_id
    ORDER BY er.created_at DESC
    LIMIT 8
')->fetchAll();

page_header('Admin Dashboard', 'dashboard', true);
?>
<section class="space-y-6">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase text-red-600">Admin Dashboard</p>
            <h1 class="mt-1 text-3xl font-black text-slate-950">Emergency Control Room</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="btn-primary" href="<?= h(url_path('admin/reports.php')) ?>">Manage Reports</a>
            <a class="btn-secondary" href="<?= h(url_path('admin/manage.php?entity=dispatch_units')) ?>">Dispatch Units</a>
        </div>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-6">
        <div class="panel p-4"><span class="text-xs font-bold uppercase text-slate-500">Pending</span><strong data-count="pending" class="mt-2 block text-3xl text-amber-600"><?= $summary['pending'] ?></strong></div>
        <div class="panel p-4"><span class="text-xs font-bold uppercase text-slate-500">Active</span><strong data-count="active" class="mt-2 block text-3xl text-red-600"><?= $summary['active'] ?></strong></div>
        <div class="panel p-4"><span class="text-xs font-bold uppercase text-slate-500">Resolved</span><strong data-count="resolved" class="mt-2 block text-3xl text-emerald-600"><?= $summary['resolved'] ?></strong></div>
        <div class="panel p-4"><span class="text-xs font-bold uppercase text-slate-500">Units Free</span><strong data-count="available-units" class="mt-2 block text-3xl text-sky-600"><?= $summary['available_units'] ?></strong></div>
        <div class="panel p-4"><span class="text-xs font-bold uppercase text-slate-500">Responders Free</span><strong data-count="available-responders" class="mt-2 block text-3xl text-indigo-600"><?= $summary['available_responders'] ?></strong></div>
        <div class="panel p-4"><span class="text-xs font-bold uppercase text-slate-500">Open Dispatch</span><strong data-count="open-assignments" class="mt-2 block text-3xl text-orange-600"><?= $summary['open_assignments'] ?></strong></div>
    </div>

    <div class="grid gap-6 lg:grid-cols-[1fr_28rem]">
        <div class="panel p-4">
            <div class="mb-3 flex items-center justify-between gap-3">
                <h2 class="text-xl font-black text-slate-950">Live Incident Map</h2>
                <span id="dashboard-status" class="text-sm text-slate-500">Loading</span>
            </div>
            <div id="dashboard-map" class="dashboard-map" data-resources="true"></div>
        </div>

        <div class="panel p-4">
            <h2 class="text-xl font-black text-slate-950">DBMS Modules</h2>
            <div class="mt-4 grid gap-2">
                <a class="btn-secondary justify-start" href="<?= h(url_path('admin/manage.php?entity=emergency_types')) ?>">Emergency Types</a>
                <a class="btn-secondary justify-start" href="<?= h(url_path('admin/manage.php?entity=dispatch_units')) ?>">Dispatch Units</a>
                <a class="btn-secondary justify-start" href="<?= h(url_path('admin/responders.php')) ?>">Responders</a>
                <a class="btn-secondary justify-start" href="<?= h(url_path('admin/manage.php?entity=hospitals')) ?>">Hospitals</a>
                <a class="btn-secondary justify-start" href="<?= h(url_path('admin/reports.php')) ?>">Emergency Reports and Assignments</a>
                <a class="btn-secondary justify-start" href="<?= h(url_path('admin/audit_log.php')) ?>">Audit Log</a>
            </div>
        </div>
    </div>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Report</th>
                    <th>Type</th>
                    <th>Severity</th>
                    <th>Status</th>
                    <th>Created</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent as $report): ?>
                    <tr>
                        <td>#<?= (int) $report['id'] ?></td>
                        <td>
                            <strong class="block text-slate-950"><?= h($report['title']) ?></strong>
                            <span class="text-sm text-slate-600"><?= h($report['address']) ?></span>
                        </td>
                        <td><?= h($report['type_name']) ?></td>
                        <td><span class="badge <?= h(severity_badge_class($report['severity'])) ?>"><?= h($report['severity']) ?></span></td>
                        <td><span class="badge <?= h(status_badge_class($report['status'])) ?>"><?= h(str_replace('_', ' ', $report['status'])) ?></span></td>
                        <td><?= h($report['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_footer(['assets/js/emergency-dashboard-map.js']); ?>
