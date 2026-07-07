<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';

$user = require_login();
redirect(role_home($user['role']));
