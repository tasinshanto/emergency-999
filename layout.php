<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';

function page_header(string $title, string $active = '', bool $withMapAssets = false): void
{
    $user = current_user();
    $flash = pop_flash();
    ?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= h($title) ?> | <?= h(APP_NAME) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <?php if ($withMapAssets): ?>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css">
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <?php endif; ?>
    <?php
    $cssPath = __DIR__ . '/../assets/css/styles.css';
    $cssVersion = file_exists($cssPath) ? filemtime($cssPath) : time();
    ?>
    <link rel="stylesheet" href="<?= h(url_path('assets/css/styles.css')) ?>?v=<?= $cssVersion ?>">
    <script>
        window.APP_BASE = <?= json_encode(app_base_url()) ?>;
        window.CSRF_TOKEN = <?= json_encode(csrf_token()) ?>;
    </script>
</head>
<body class="min-h-screen bg-slate-100 text-slate-900">
<header class="border-b border-slate-200 bg-white">
    <div class="mx-auto flex max-w-7xl items-center justify-between px-4 py-3">
        <a href="<?= h(url_path('index.php')) ?>" class="flex items-center gap-3">
            <span class="grid h-10 w-10 place-items-center rounded bg-red-600 text-sm font-black text-white">999</span>
            <span>
                <span class="block text-sm font-bold tracking-wide text-slate-950"><?= h(APP_NAME) ?></span>
                <span class="block text-xs text-slate-500">Live Emergency Response</span>
            </span>
        </a>
        <nav class="flex flex-wrap items-center justify-end gap-2 text-sm">
            <a class="nav-link <?= $active === 'map' ? 'nav-active' : '' ?>" href="<?= h(url_path('index.php')) ?>">Map</a>
            <?php if ($user): ?>
                <a class="nav-link <?= $active === 'dashboard' ? 'nav-active' : '' ?>" href="<?= h(url_path(role_home($user['role']))) ?>">Dashboard</a>
                <?php if ($user['role'] === 'admin'): ?>
                    <a class="nav-link <?= $active === 'reports' ? 'nav-active' : '' ?>" href="<?= h(url_path('admin/reports.php')) ?>">Reports</a>
                    <a class="nav-link <?= $active === 'manage' ? 'nav-active' : '' ?>" href="<?= h(url_path('admin/manage.php?entity=emergency_types')) ?>">Manage</a>
                    <a class="nav-link <?= $active === 'audit' ? 'nav-active' : '' ?>" href="<?= h(url_path('admin/audit_log.php')) ?>">Audit Log</a>
                <?php endif; ?>
                <span class="hidden text-slate-400 sm:inline">|</span>
                <span class="hidden max-w-48 truncate text-slate-600 sm:inline"><?= h($user['name']) ?></span>
                <a class="nav-link" href="<?= h(url_path('auth/logout.php')) ?>">Logout</a>
            <?php else: ?>
                <a class="nav-link <?= $active === 'login' ? 'nav-active' : '' ?>" href="<?= h(url_path('auth/login.php')) ?>">Login</a>
                <a class="btn-primary" href="<?= h(url_path('auth/register.php')) ?>">Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="mx-auto max-w-7xl px-4 py-6">
    <?php if ($flash): ?>
        <div class="mb-5 rounded border px-4 py-3 text-sm <?= h(status_badge_class($flash['type'] === 'error' ? 'rejected' : ($flash['type'] === 'success' ? 'resolved' : 'pending'))) ?>">
            <?= h($flash['message']) ?>
        </div>
    <?php endif; ?>
<?php
}

function page_footer(array $scripts = []): void
{
    ?>
</main>
<?php foreach ($scripts as $script): ?>
    <?php
    $filePath = __DIR__ . '/../' . ltrim($script, '/');
    $version = file_exists($filePath) ? filemtime($filePath) : time();
    ?>
    <script src="<?= h(url_path($script)) ?>?v=<?= $version ?>"></script>
<?php endforeach; ?>
</body>
</html>
<?php
}
