<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';
require_once __DIR__ . '/../includes/audit.php';

if (current_user()) {
    redirect(role_home(current_user()['role']));
}

$error = '';
if (is_post()) {
    verify_csrf();
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    try {
        if (login_user($email, $password)) {
            $user = current_user();
            audit_log('login', 'session', (int) $user['id'], $user['email'], 'User logged in', actor: $user);
            redirect(role_home($user['role']));
        }
        $error = 'Invalid email or password.';
    } catch (Throwable $e) {
        $error = 'Login is unavailable until the database is configured.';
    }
}

page_header('Login', 'login');
?>
<section class="mx-auto grid max-w-5xl gap-6 lg:grid-cols-[1fr_24rem]">
    <div class="panel p-6">
        <p class="text-xs font-bold uppercase text-red-600">Role-based access</p>
        <h1 class="mt-2 text-3xl font-black text-slate-950">Emergency Operations Login</h1>
        <p class="mt-3 max-w-2xl text-slate-600">
            Admins manage the DB, citizens submit reports and responders update dispatch assignments.
        </p>
        <div class="mt-6 grid gap-3 text-sm sm:grid-cols-3">
            <div class="rounded border border-slate-200 bg-slate-50 p-4">
                <strong class="block text-slate-950">Admin</strong>
                <span class="text-slate-600">admin@999.local</span>
            </div>
            <div class="rounded border border-slate-200 bg-slate-50 p-4">
                <strong class="block text-slate-950">User</strong>
                <span class="text-slate-600">user@999.local</span>
            </div>
            <div class="rounded border border-slate-200 bg-slate-50 p-4">
                <strong class="block text-slate-950">Responder</strong>
                <span class="text-slate-600">responder@999.local</span>
            </div>
        </div>
        <p class="mt-4 text-sm font-semibold text-slate-600">Seed password for all demo accounts: <span class="text-slate-950">password</span></p>
    </div>

    <form method="post" class="panel space-y-4 p-6">
        <?= csrf_input() ?>
        <h2 class="text-xl font-black text-slate-950">Sign in</h2>
        <?php if ($error): ?>
            <div class="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800"><?= h($error) ?></div>
        <?php endif; ?>
        <div>
            <label class="field-label" for="email">Email</label>
            <input class="field-input" id="email" name="email" type="email" required autofocus value="<?= h($_POST['email'] ?? '') ?>">
        </div>
        <div>
            <label class="field-label" for="password">Password</label>
            <input class="field-input" id="password" name="password" type="password" required>
        </div>
        <button class="btn-primary w-full" type="submit">Login</button>
        <a class="btn-secondary w-full" href="<?= h(url_path('auth/register.php')) ?>">Create user account</a>
    </form>
</section>
<?php page_footer(); ?>
