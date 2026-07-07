<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audit.php';

require_login('admin');
$pdo = db();

$entities = [
    'emergency_types' => [
        'title' => 'Emergency Types',
        'table' => 'Emergency_Type',
        'fields' => [
            'name' => ['label' => 'Name', 'type' => 'text', 'required' => true],
            'description' => ['label' => 'Description', 'type' => 'textarea'],
            'priority_level' => ['label' => 'Priority', 'type' => 'select', 'options' => ['low', 'medium', 'high', 'critical'], 'required' => true],
            'icon' => ['label' => 'Icon Key', 'type' => 'text', 'required' => true],
            'color' => ['label' => 'Map Color', 'type' => 'color', 'required' => true],
            'is_active' => ['label' => 'Active', 'type' => 'checkbox'],
        ],
        'order' => 'ORDER BY name',
    ],
    'dispatch_units' => [
        'title' => 'Dispatch Units',
        'table' => 'Dispatch_Unit',
        'fields' => [
            'unit_name' => ['label' => 'Unit Name', 'type' => 'text', 'required' => true],
            'unit_type' => ['label' => 'Unit Type', 'type' => 'select', 'options' => ['fire', 'ambulance', 'police', 'rescue', 'other'], 'required' => true],
            'phone' => ['label' => 'Phone', 'type' => 'text', 'required' => true],
            'base_address' => ['label' => 'Base Address', 'type' => 'textarea', 'required' => true],
            'latitude' => ['label' => 'Latitude', 'type' => 'number', 'step' => '0.0000001', 'required' => true],
            'longitude' => ['label' => 'Longitude', 'type' => 'number', 'step' => '0.0000001', 'required' => true],
            'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['available', 'busy', 'offline'], 'required' => true],
            'capacity' => ['label' => 'Capacity', 'type' => 'number', 'step' => '1', 'required' => true],
        ],
        'order' => 'ORDER BY unit_name',
    ],
    'hospitals' => [
        'title' => 'Hospitals',
        'table' => 'Hospital',
        'fields' => [
            'name' => ['label' => 'Name', 'type' => 'text', 'required' => true],
            'phone' => ['label' => 'Phone', 'type' => 'text', 'required' => true],
            'address' => ['label' => 'Address', 'type' => 'textarea', 'required' => true],
            'latitude' => ['label' => 'Latitude', 'type' => 'number', 'step' => '0.0000001', 'required' => true],
            'longitude' => ['label' => 'Longitude', 'type' => 'number', 'step' => '0.0000001', 'required' => true],
            'available_beds' => ['label' => 'Available Beds', 'type' => 'number', 'step' => '1', 'required' => true],
            'emergency_capacity' => ['label' => 'Emergency Capacity', 'type' => 'number', 'step' => '1', 'required' => true],
            'status' => ['label' => 'Status', 'type' => 'select', 'options' => ['available', 'busy', 'offline'], 'required' => true],
        ],
        'order' => 'ORDER BY name',
    ],
];

$entityKey = (string) ($_GET['entity'] ?? 'emergency_types');
if (!isset($entities[$entityKey])) {
    redirect('admin/manage.php?entity=emergency_types');
}
$entity = $entities[$entityKey];
$table = $entity['table'];
$fields = $entity['fields'];
$auditEntityType = match ($entityKey) {
    'emergency_types' => 'emergency_type',
    'dispatch_units' => 'dispatch_unit',
    'hospitals' => 'hospital',
    default => $entityKey,
};
$auditLabelField = match ($entityKey) {
    'emergency_types' => 'name',
    'dispatch_units' => 'unit_name',
    'hospitals' => 'name',
    default => 'id',
};
$error = '';

$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM `$table` WHERE id = ?");
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

if (is_post()) {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'save');
    $id = (int) ($_POST['id'] ?? 0);

    try {
        if ($action === 'set_status') {
            $newStatus = trim((string) ($_POST['new_status'] ?? ''));
            if ($id <= 0) {
                throw new RuntimeException('Invalid record.');
            }
            // Validate allowed statuses per entity
            $allowedStatuses = [];
            if ($entityKey === 'dispatch_units') {
                $allowedStatuses = ['available', 'busy', 'offline'];
            } elseif ($entityKey === 'hospitals') {
                $allowedStatuses = ['available', 'busy', 'offline'];
            }
            if (!in_array($newStatus, $allowedStatuses, true)) {
                throw new RuntimeException('Invalid status value.');
            }
            $before = audit_fetch_row($pdo, $table, $id);
            $stmt = $pdo->prepare("UPDATE `$table` SET status = ? WHERE id = ?");
            $stmt->execute([$newStatus, $id]);
            audit_log(
                'status_changed',
                $auditEntityType,
                $id,
                $before ? (string) ($before[$auditLabelField] ?? ('#' . $id)) : ('#' . $id),
                null,
                'status',
                $before['status'] ?? null,
                $newStatus,
                audit_current_actor(),
            );
            set_flash('success', 'Status updated to "' . $newStatus . '".');
            redirect('admin/manage.php?entity=' . $entityKey);
        }

        if ($action === 'delete') {
            if ($id <= 0) {
                throw new RuntimeException('Invalid record.');
            }
            $before = audit_fetch_row($pdo, $table, $id);
            $stmt = $pdo->prepare("DELETE FROM `$table` WHERE id = ?");
            $stmt->execute([$id]);
            audit_log(
                'deleted',
                $auditEntityType,
                $id,
                $before ? (string) ($before[$auditLabelField] ?? ('#' . $id)) : ('#' . $id),
                'Admin deleted ' . audit_entity_label($auditEntityType),
                actor: audit_current_actor(),
            );
            set_flash('success', 'Record deleted.');
            redirect('admin/manage.php?entity=' . $entityKey);
        }

        $values = [];
        foreach ($fields as $name => $meta) {
            if (($meta['type'] ?? 'text') === 'checkbox') {
                $values[$name] = isset($_POST[$name]) ? 1 : 0;
                continue;
            }

            $value = trim((string) ($_POST[$name] ?? ''));
            if (($meta['required'] ?? false) && $value === '') {
                throw new RuntimeException($meta['label'] . ' is required.');
            }
            if (($meta['type'] ?? '') === 'select' && !in_array($value, $meta['options'], true)) {
                throw new RuntimeException('Invalid ' . $meta['label'] . '.');
            }
            $values[$name] = $value;
        }

        if ($id > 0) {
            $before = audit_fetch_row($pdo, $table, $id);
            $sets = implode(', ', array_map(fn ($name) => "`$name` = ?", array_keys($values)));
            $stmt = $pdo->prepare("UPDATE `$table` SET $sets WHERE id = ?");
            $stmt->execute([...array_values($values), $id]);
            $label = (string) ($values[$auditLabelField] ?? ($before[$auditLabelField] ?? ('#' . $id)));
            if ($before) {
                $after = array_merge($before, $values);
                audit_log_changes(
                    'updated',
                    $auditEntityType,
                    $id,
                    $label,
                    audit_compare_rows($before, $after, array_keys($values)),
                    audit_current_actor(),
                );
            }
            set_flash('success', 'Record updated.');
        } else {
            $columns = implode(', ', array_map(fn ($name) => "`$name`", array_keys($values)));
            $placeholders = implode(', ', array_fill(0, count($values), '?'));
            $stmt = $pdo->prepare("INSERT INTO `$table` ($columns) VALUES ($placeholders)");
            $stmt->execute(array_values($values));
            $newId = (int) $pdo->lastInsertId();
            $label = (string) ($values[$auditLabelField] ?? ('#' . $newId));
            audit_log('created', $auditEntityType, $newId, $label, 'Admin created ' . audit_entity_label($auditEntityType) . ': ' . $label, actor: audit_current_actor());
            set_flash('success', 'Record created.');
        }

        // After saving a dispatch unit, recalculate status from active load vs capacity
        if ($entityKey === 'dispatch_units') {
            $recalcId = $id > 0 ? $id : ($newId ?? 0);
            if ($recalcId > 0) {
                $pdo->prepare("
                    UPDATE Dispatch_Unit
                    SET status = CASE
                        WHEN status = 'offline' THEN 'offline'
                        WHEN active_assignments_count >= capacity THEN 'busy'
                        ELSE 'available'
                    END
                    WHERE id = ?
                ")->execute([$recalcId]);
            }
        }

        redirect('admin/manage.php?entity=' . $entityKey);
    } catch (PDOException $e) {
        $error = 'Database action failed. The record may already exist or be in use.';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$rows = $pdo->query("SELECT * FROM `$table` {$entity['order']}")->fetchAll();

page_header($entity['title'], 'manage');
?>
<section class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase text-red-600">Admin CRUD</p>
            <h1 class="mt-1 text-3xl font-black text-slate-950"><?= h($entity['title']) ?></h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <?php foreach ($entities as $key => $meta): ?>
                <a class="<?= $key === $entityKey ? 'btn-primary' : 'btn-secondary' ?>" href="<?= h(url_path('admin/manage.php?entity=' . $key)) ?>"><?= h($meta['title']) ?></a>
            <?php endforeach; ?>
            <a class="btn-secondary" href="<?= h(url_path('admin/responders.php')) ?>">Responders</a>
        </div>
    </div>

    <form method="post" class="panel space-y-4 p-5">
        <?= csrf_input() ?>
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
        <input type="hidden" name="action" value="save">
        <h2 class="text-xl font-black text-slate-950"><?= $editing ? 'Edit Record' : 'Create Record' ?></h2>
        <?php if ($error): ?>
            <div class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"><?= h($error) ?></div>
        <?php endif; ?>
        <div class="grid gap-4 md:grid-cols-2">
            <?php foreach ($fields as $name => $meta): ?>
                <?php $value = $editing[$name] ?? ($meta['type'] === 'checkbox' ? 1 : ''); ?>
                <div class="<?= ($meta['type'] ?? '') === 'textarea' ? 'md:col-span-2' : '' ?>">
                    <label class="field-label" for="<?= h($name) ?>"><?= h($meta['label']) ?></label>
                    <?php if (($meta['type'] ?? 'text') === 'textarea'): ?>
                        <textarea class="field-textarea" id="<?= h($name) ?>" name="<?= h($name) ?>" <?= ($meta['required'] ?? false) ? 'required' : '' ?>><?= h($value) ?></textarea>
                    <?php elseif (($meta['type'] ?? 'text') === 'select'): ?>
                        <select class="field-select" id="<?= h($name) ?>" name="<?= h($name) ?>" <?= ($meta['required'] ?? false) ? 'required' : '' ?>>
                            <?php foreach ($meta['options'] as $option): ?>
                                <option value="<?= h($option) ?>" <?= (string) $value === $option ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $option))) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif (($meta['type'] ?? 'text') === 'checkbox'): ?>
                        <label class="inline-flex items-center gap-2 rounded border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700">
                            <input type="checkbox" name="<?= h($name) ?>" value="1" <?= (int) $value === 1 ? 'checked' : '' ?>>
                            Enabled
                        </label>
                    <?php else: ?>
                        <input class="field-input" id="<?= h($name) ?>" name="<?= h($name) ?>" type="<?= h($meta['type'] ?? 'text') ?>" step="<?= h($meta['step'] ?? '') ?>" value="<?= h($value) ?>" <?= ($meta['required'] ?? false) ? 'required' : '' ?>>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        <div class="flex flex-wrap gap-2">
            <button class="btn-primary" type="submit"><?= $editing ? 'Update' : 'Create' ?></button>
            <?php if ($editing): ?>
                <a class="btn-secondary" href="<?= h(url_path('admin/manage.php?entity=' . $entityKey)) ?>">Cancel Edit</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <?php foreach (array_keys($fields) as $field): ?>
                        <th><?= h(ucwords(str_replace('_', ' ', $field))) ?></th>
                    <?php endforeach; ?>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rows as $row): ?>
                    <tr>
                        <td>#<?= (int) $row['id'] ?></td>
                        <?php foreach (array_keys($fields) as $field): ?>
                            <td>
                                <?php if ($field === 'color'): ?>
                                    <span class="inline-flex items-center gap-2"><span class="h-5 w-5 rounded border" style="background: <?= h($row[$field]) ?>"></span><?= h($row[$field]) ?></span>
                                <?php elseif (str_contains($field, 'status') || $field === 'priority_level'): ?>
                                    <span class="badge <?= h(status_badge_class((string) $row[$field])) ?>"><?= h(str_replace('_', ' ', (string) $row[$field])) ?></span>
                                <?php elseif ($field === 'is_active'): ?>
                                    <span class="badge <?= (int) $row[$field] === 1 ? 'bg-emerald-100 text-emerald-800 border-emerald-200' : 'bg-slate-100 text-slate-700 border-slate-200' ?>"><?= (int) $row[$field] === 1 ? 'active' : 'inactive' ?></span>
                                <?php elseif ($field === 'capacity' && $entityKey === 'dispatch_units'): ?>
                                    <span class="font-semibold"><?= (int) ($row['active_assignments_count'] ?? 0) ?> / <?= (int) $row[$field] ?></span>
                                    <span class="text-xs text-slate-400 ml-1">(active/max)</span>
                                <?php else: ?>
                                    <?= h($row[$field]) ?>
                                <?php endif; ?>
                            </td>
                        <?php endforeach; ?>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                <a class="btn-secondary" href="<?= h(url_path('admin/manage.php?entity=' . $entityKey . '&edit=' . (int) $row['id'])) ?>">Edit</a>
                                <?php if (in_array($entityKey, ['dispatch_units', 'hospitals'], true) && isset($row['status'])): ?>
                                    <?php
                                    $statuses = ['available', 'busy', 'offline'];
                                    $currentStatus = (string) $row['status'];
                                    ?>
                                    <form method="post" class="inline-flex items-center gap-1">
                                        <?= csrf_input() ?>
                                        <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                        <input type="hidden" name="action" value="set_status">
                                        <select name="new_status" class="field-select !w-auto !py-1 !px-2 !text-xs !min-h-0" onchange="this.form.submit()">
                                            <?php foreach ($statuses as $s): ?>
                                                <option value="<?= h($s) ?>" <?= $currentStatus === $s ? 'selected' : '' ?>><?= h(ucfirst($s)) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </form>
                                <?php endif; ?>
                                <form method="post" onsubmit="return confirm('Delete this record?');">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <button class="btn-danger" type="submit">Delete</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php page_footer(); ?>
