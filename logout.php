<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/audit.php';

$user = current_user();
if ($user) {
    audit_log('logout', 'session', (int) $user['id'], $user['email'], 'User logged out', actor: $user);
}

logout_user();
redirect('index.php');
