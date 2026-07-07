<?php
/** Session auth: login state, role checks (admin / user / responder), redirects. */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/helpers.php';

start_app_session();

function current_user(): ?array
{
    static $cached = false;
    static $cached_session_id = null;

    $current_session_id = $_SESSION['user_id'] ?? null;

    // Return cache only if the session user_id hasn't changed
    if ($cached !== false && $cached_session_id === $current_session_id) {
        return $cached;
    }

    if (!$current_session_id) {
        $cached = null;
        $cached_session_id = null;
        return null;
    }

    $stmt = db()->prepare('SELECT * FROM Users WHERE id = ? AND status = "active" LIMIT 1');
    $stmt->execute([$current_session_id]);
    $user = $stmt->fetch();
    $cached = $user ?: null;
    $cached_session_id = $current_session_id;

    if (!$cached) {
        unset($_SESSION['user_id']);
    }

    return $cached;
}

function login_user(string $email, string $password): bool
{
    $stmt = db()->prepare('SELECT * FROM Users WHERE email = ? AND status = "active" LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        return false;
    }

    session_regenerate_id(true);
    $_SESSION['user_id'] = (int) $user['id'];
    return true;
}

function logout_user(): void
{
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
}

function role_home(string $role): string
{
    return match ($role) {
        'admin' => 'admin/index.php',
        'responder' => 'responder/index.php',
        default => 'user/dashboard.php',
    };
}

function require_login(string|array|null $roles = null): array
{
    $user = current_user();
    if (!$user) {
        set_flash('warning', 'Please log in to continue.');
        redirect('auth/login.php');
    }

    if ($roles !== null) {
        $allowed = is_array($roles) ? $roles : [$roles];
        if (!in_array($user['role'], $allowed, true)) {
            set_flash('error', 'You do not have access to that area.');
            redirect(role_home($user['role']));
        }
    }

    return $user;
}
