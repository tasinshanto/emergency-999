<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audit.php';

$error = '';
if (is_post()) {
    verify_csrf();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    $phone = trim((string) ($_POST['phone'] ?? ''));
    $address = trim((string) ($_POST['address'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    if ($name === '' || $email === '' || $phone === '' || strlen($password) < 6) {
        $error = 'Name, email, phone, and a 6 character password are required.';
    } else {
        try {
            $stmt = db()->prepare('
                INSERT INTO Users (name, email, phone, password_hash, role, address, status)
                VALUES (?, ?, ?, ?, "user", ?, "active")
            ');
            $stmt->execute([$name, $email, $phone, password_hash($password, PASSWORD_DEFAULT), $address]);
            $userId = (int) db()->lastInsertId();
            audit_log('created', 'user', $userId, $name, 'New citizen account registered: ' . $email, actor: [
                'id' => $userId,
                'name' => $name,
                'role' => 'user',
            ]);
            set_flash('success', 'User account created. You can log in now.');
            redirect('auth/login.php');
        } catch (PDOException $e) {
            $error = str_contains($e->getMessage(), 'Duplicate') ? 'That email is already registered.' : 'Registration failed.';
        } catch (Throwable $e) {
            $error = 'Registration is unavailable until the database is configured.';
        }
    }
}

page_header('Register', 'login');
?>
<form method="post" class="panel mx-auto max-w-2xl space-y-4 p-6">
    <?= csrf_input() ?>
    <div>
        <p class="text-xs font-bold uppercase text-red-600">Citizen account</p>
        <h1 class="mt-2 text-2xl font-black text-slate-950">Register as User</h1>
    </div>
    <?php if ($error): ?>
        <div class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"><?= h($error) ?></div>
    <?php endif; ?>
    <div class="grid gap-4 sm:grid-cols-2">
        <div>
            <label class="field-label" for="name">Name</label>
            <input class="field-input" id="name" name="name" required value="<?= h($_POST['name'] ?? '') ?>">
        </div>
        <div>
            <label class="field-label" for="phone">Phone</label>
            <input class="field-input" id="phone" name="phone" required value="<?= h($_POST['phone'] ?? '') ?>">
        </div>
    </div>
    <div>
        <label class="field-label" for="email">Email</label>
        <input class="field-input" id="email" name="email" type="email" required value="<?= h($_POST['email'] ?? '') ?>">
    </div>
    <div>
        <label class="field-label" for="password">Password</label>
        <input class="field-input" id="password" name="password" type="password" minlength="6" required>
    </div>
    <div>
        <label class="field-label" for="address">Address</label>
        <textarea class="field-textarea" id="address" name="address"><?= h($_POST['address'] ?? '') ?></textarea>
    </div>
    <button class="btn-primary w-full" type="submit">Create Account</button>
</form>
<?php page_footer(); ?>
