<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audit.php';

require_login('admin');
$pdo = db();
$error = '';

$units = $pdo->query('SELECT id, unit_name FROM Dispatch_Unit ORDER BY unit_name')->fetchAll();
$editId = (int) ($_GET['edit'] ?? 0);
$editing = null;
if ($editId > 0) {
    $stmt = $pdo->prepare('
        SELECT r.*, u.name, u.email, u.phone, u.address, u.status AS user_status
        FROM Responder r
        INNER JOIN Users u ON u.id = r.user_id
        WHERE r.id = ?
    ');
    $stmt->execute([$editId]);
    $editing = $stmt->fetch() ?: null;
}

if (is_post()) {
    verify_csrf();
    $action = (string) ($_POST['action'] ?? 'save');
    $id = (int) ($_POST['id'] ?? 0);

    try {
        if ($action === 'set_availability') {
            $newAvailability = trim((string) ($_POST['new_availability'] ?? ''));
            if ($id <= 0) {
                throw new RuntimeException('Invalid responder.');
            }
            if (!in_array($newAvailability, ['available', 'assigned', 'on_scene', 'offline'], true)) {
                throw new RuntimeException('Invalid availability status.');
            }
            $before = audit_fetch_row($pdo, 'Responder', $id);
            $label = audit_responder_label($pdo, $id);
            $stmt = $pdo->prepare('UPDATE Responder SET availability_status = ? WHERE id = ?');
            $stmt->execute([$newAvailability, $id]);
            audit_log(
                'status_changed',
                'responder',
                $id,
                $label,
                null,
                'availability_status',
                $before['availability_status'] ?? null,
                $newAvailability,
                audit_current_actor(),
            );
            set_flash('success', 'Responder availability updated to "' . str_replace('_', ' ', $newAvailability) . '".');
            redirect('admin/responders.php');
        }

        if ($action === 'delete') {
            $stmt = $pdo->prepare('SELECT user_id FROM Responder WHERE id = ?');
            $stmt->execute([$id]);
            $row = $stmt->fetch();
            if (!$row) {
                throw new RuntimeException('Responder not found.');
            }
            $label = audit_responder_label($pdo, $id);
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM Responder WHERE id = ?')->execute([$id]);
            $pdo->prepare('DELETE FROM Users WHERE id = ? AND role = "responder"')->execute([$row['user_id']]);
            $pdo->commit();
            audit_log('deleted', 'responder', $id, $label, 'Admin deleted responder: ' . $label, actor: audit_current_actor());
            set_flash('success', 'Responder deleted.');
            redirect('admin/responders.php');
        }

        $name = trim((string) ($_POST['name'] ?? ''));
        $email = strtolower(trim((string) ($_POST['email'] ?? '')));
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $password = (string) ($_POST['password'] ?? '');
        $address = trim((string) ($_POST['address'] ?? ''));
        $dispatchUnitId = (int) ($_POST['dispatch_unit_id'] ?? 0);
        $designation = trim((string) ($_POST['designation'] ?? ''));
        $badgeNo = trim((string) ($_POST['badge_no'] ?? ''));
        $specialization = trim((string) ($_POST['specialization'] ?? ''));
        $availabilityStatus = (string) ($_POST['availability_status'] ?? 'available');
        $userStatus = (string) ($_POST['user_status'] ?? 'active');

        if ($name === '' || $email === '' || $phone === '' || $designation === '' || $badgeNo === '') {
            throw new RuntimeException('Name, email, phone, designation, and badge number are required.');
        }
        if ($id <= 0 && strlen($password) < 6) {
            throw new RuntimeException('New responders need a 6 character password.');
        }
        if (!in_array($availabilityStatus, ['available', 'assigned', 'on_scene', 'offline'], true)) {
            throw new RuntimeException('Invalid availability status.');
        }
        if (!in_array($userStatus, ['active', 'inactive', 'blocked'], true)) {
            throw new RuntimeException('Invalid account status.');
        }

        $pdo->beginTransaction();
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT user_id FROM Responder WHERE id = ? FOR UPDATE');
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            if (!$existing) {
                throw new RuntimeException('Responder not found.');
            }

            $userSql = 'UPDATE Users SET name = ?, email = ?, phone = ?, address = ?, status = ?';
            $userParams = [$name, $email, $phone, $address, $userStatus];
            if ($password !== '') {
                $userSql .= ', password_hash = ?';
                $userParams[] = password_hash($password, PASSWORD_DEFAULT);
            }
            $userSql .= ' WHERE id = ?';
            $userParams[] = $existing['user_id'];
            $pdo->prepare($userSql)->execute($userParams);

            $responderBefore = audit_fetch_row($pdo, 'Responder', $id);
            $pdo->prepare('
                UPDATE Responder
                SET dispatch_unit_id = ?, designation = ?, badge_no = ?, specialization = ?, availability_status = ?
                WHERE id = ?
            ')->execute([$dispatchUnitId ?: null, $designation, $badgeNo, $specialization, $availabilityStatus, $id]);
            $pdo->commit();
            audit_log_changes('updated', 'responder', $id, $name, audit_compare_rows(
                array_merge($responderBefore ?: [], ['name' => $name]),
                [
                    'designation' => $designation,
                    'badge_no' => $badgeNo,
                    'specialization' => $specialization,
                    'availability_status' => $availabilityStatus,
                ],
                ['designation', 'badge_no', 'specialization', 'availability_status'],
            ), audit_current_actor());
            set_flash('success', 'Responder updated.');
        } else {
            $pdo->prepare('
                INSERT INTO Users (name, email, phone, password_hash, role, address, status)
                VALUES (?, ?, ?, ?, "responder", ?, ?)
            ')->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $address, $userStatus]);
            $userId = (int) $pdo->lastInsertId();
            $pdo->prepare('
                INSERT INTO Responder (user_id, dispatch_unit_id, designation, badge_no, specialization, availability_status)
                VALUES (?, ?, ?, ?, ?, ?)
            ')->execute([$userId, $dispatchUnitId ?: null, $designation, $badgeNo, $specialization, $availabilityStatus]);
            $responderId = (int) $pdo->lastInsertId();
            $pdo->commit();
            audit_log('created', 'responder', $responderId, $name, 'Admin created responder: ' . $name . ' (' . $badgeNo . ')', actor: audit_current_actor());
            set_flash('success', 'Responder created.');
        }

        redirect('admin/responders.php');
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = 'Responder action failed. Email or badge number may already exist.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $error = $e->getMessage();
    }
}

$responders = $pdo->query('
    SELECT r.*, u.name, u.email, u.phone, u.status AS user_status, du.unit_name
    FROM Responder r
    INNER JOIN Users u ON u.id = r.user_id
    LEFT JOIN Dispatch_Unit du ON du.id = r.dispatch_unit_id
    ORDER BY u.name
')->fetchAll();

page_header('Responders', 'manage');
?>
<section class="space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div>
            <p class="text-xs font-bold uppercase text-red-600">Admin CRUD</p>
            <h1 class="mt-1 text-3xl font-black text-slate-950">Responders</h1>
        </div>
        <div class="flex flex-wrap gap-2">
            <a class="btn-secondary" href="<?= h(url_path('admin/manage.php?entity=emergency_types')) ?>">Emergency Types</a>
            <a class="btn-secondary" href="<?= h(url_path('admin/manage.php?entity=dispatch_units')) ?>">Dispatch Units</a>
            <a class="btn-secondary" href="<?= h(url_path('admin/manage.php?entity=hospitals')) ?>">Hospitals</a>
        </div>
    </div>

    <form method="post" class="panel space-y-4 p-5">
        <?= csrf_input() ?>
        <input type="hidden" name="id" value="<?= (int) ($editing['id'] ?? 0) ?>">
        <input type="hidden" name="action" value="save">
        <h2 class="text-xl font-black text-slate-950"><?= $editing ? 'Edit Responder' : 'Create Responder' ?></h2>
        <?php if ($error): ?>
            <div class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"><?= h($error) ?></div>
        <?php endif; ?>
        <div class="grid gap-4 md:grid-cols-2">
            <div>
                <label class="field-label" for="name">Name</label>
                <input class="field-input" id="name" name="name" required value="<?= h($editing['name'] ?? '') ?>">
            </div>
            <div>
                <label class="field-label" for="email">Email</label>
                <input class="field-input" id="email" name="email" type="email" required value="<?= h($editing['email'] ?? '') ?>">
            </div>
            <div>
                <label class="field-label" for="phone">Phone</label>
                <input class="field-input" id="phone" name="phone" required value="<?= h($editing['phone'] ?? '') ?>">
            </div>
            <div>
                <label class="field-label" for="password"><?= $editing ? 'New Password' : 'Password' ?></label>
                <input class="field-input" id="password" name="password" type="password" <?= $editing ? '' : 'required minlength="6"' ?>>
            </div>
            <div>
                <label class="field-label" for="dispatch_unit_id">Dispatch Unit</label>
                <select class="field-select" id="dispatch_unit_id" name="dispatch_unit_id">
                    <option value="">No unit</option>
                    <?php foreach ($units as $unit): ?>
                        <option value="<?= (int) $unit['id'] ?>" <?= (int) ($editing['dispatch_unit_id'] ?? 0) === (int) $unit['id'] ? 'selected' : '' ?>><?= h($unit['unit_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label" for="designation">Designation</label>
                <input class="field-input" id="designation" name="designation" required value="<?= h($editing['designation'] ?? '') ?>">
            </div>
            <div>
                <label class="field-label" for="badge_no">Badge No</label>
                <input class="field-input" id="badge_no" name="badge_no" required value="<?= h($editing['badge_no'] ?? '') ?>">
            </div>
            <div>
                <label class="field-label" for="specialization">Specialization</label>
                <input class="field-input" id="specialization" name="specialization" value="<?= h($editing['specialization'] ?? '') ?>">
            </div>
            <div>
                <label class="field-label" for="availability_status">Availability</label>
                <select class="field-select" id="availability_status" name="availability_status">
                    <?php foreach (['available', 'assigned', 'on_scene', 'offline'] as $status): ?>
                        <option value="<?= h($status) ?>" <?= ($editing['availability_status'] ?? 'available') === $status ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $status))) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="field-label" for="user_status">Account Status</label>
                <select class="field-select" id="user_status" name="user_status">
                    <?php foreach (['active', 'inactive', 'blocked'] as $status): ?>
                        <option value="<?= h($status) ?>" <?= ($editing['user_status'] ?? 'active') === $status ? 'selected' : '' ?>><?= h(ucfirst($status)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="md:col-span-2">
                <label class="field-label" for="address">Address</label>
                <textarea class="field-textarea" id="address" name="address"><?= h($editing['address'] ?? '') ?></textarea>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <button class="btn-primary" type="submit"><?= $editing ? 'Update' : 'Create' ?></button>
            <?php if ($editing): ?>
                <a class="btn-secondary" href="<?= h(url_path('admin/responders.php')) ?>">Cancel Edit</a>
            <?php endif; ?>
        </div>
    </form>

    <div class="table-wrap">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Responder</th>
                    <th>Unit</th>
                    <th>Designation</th>
                    <th>Availability</th>
                    <th>Account</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($responders as $responder): ?>
                    <tr>
                        <td>
                            <strong class="block text-slate-950"><?= h($responder['name']) ?></strong>
                            <span class="text-sm text-slate-600"><?= h($responder['email']) ?> | <?= h($responder['phone']) ?></span>
                            <span class="mt-1 block text-xs text-slate-500"><?= h($responder['badge_no']) ?></span>
                        </td>
                        <td><?= h($responder['unit_name'] ?? 'Unassigned') ?></td>
                        <td>
                            <span class="block"><?= h($responder['designation']) ?></span>
                            <span class="text-sm text-slate-600"><?= h($responder['specialization']) ?></span>
                        </td>
                        <td><span class="badge <?= h(status_badge_class($responder['availability_status'])) ?>"><?= h(str_replace('_', ' ', $responder['availability_status'])) ?></span></td>
                        <td><span class="badge <?= h(status_badge_class($responder['user_status'])) ?>"><?= h($responder['user_status']) ?></span></td>
                        <td>
                            <div class="flex flex-wrap gap-2">
                                <a class="btn-secondary" href="<?= h(url_path('admin/responders.php?edit=' . (int) $responder['id'])) ?>">Edit</a>
                                <form method="post" class="inline-flex items-center gap-1">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="id" value="<?= (int) $responder['id'] ?>">
                                    <input type="hidden" name="action" value="set_availability">
                                    <select name="new_availability" class="field-select !w-auto !py-1 !px-2 !text-xs !min-h-0" onchange="this.form.submit()">
                                        <?php foreach (['available', 'assigned', 'on_scene', 'offline'] as $avail): ?>
                                            <option value="<?= h($avail) ?>" <?= $responder['availability_status'] === $avail ? 'selected' : '' ?>><?= h(ucwords(str_replace('_', ' ', $avail))) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </form>
                                <form method="post" onsubmit="return confirm('Delete this responder account?');">
                                    <?= csrf_input() ?>
                                    <input type="hidden" name="id" value="<?= (int) $responder['id'] ?>">
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
