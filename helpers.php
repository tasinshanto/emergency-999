<?php
/**
 * Shared helpers: sessions, URLs, CSRF, HTML escape, JSON API responses, status badges.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $cookiePath = '/';
    if (defined('BASE_URL') && BASE_URL !== '') {
        $cookiePath = rtrim((string) BASE_URL, '/') ?: '/';
    }

    $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((int) ($_SERVER['SERVER_PORT'] ?? 0) === 443);

    session_set_cookie_params([
        'lifetime' => 0,
        'path' => $cookiePath,
        'secure' => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function app_base_url(): string
{
    if (BASE_URL !== '') {
        return rtrim(BASE_URL, '/');
    }

    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    foreach (['/admin/', '/api/', '/auth/', '/user/', '/responder/'] as $marker) {
        $pos = strpos($script, $marker);
        if ($pos !== false) {
            return rtrim(substr($script, 0, $pos), '/');
        }
    }

    $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
    return $dir === '/' || $dir === '.' ? '' : $dir;
}

function url_path(string $path = ''): string
{
    return app_base_url() . '/' . ltrim($path, '/');
}

function redirect(string $path): never
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Location: ' . url_path($path));
    exit;
}

function is_post(): bool
{
    return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function input_value(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $_GET[$key] ?? $default;
}

function set_flash(string $type, string $message): void
{
    start_app_session();
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function pop_flash(): ?array
{
    start_app_session();
    $flash = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $flash;
}

function csrf_token(): string
{
    start_app_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_input(): string
{
    return '<input type="hidden" name="csrf_token" value="' . h(csrf_token()) . '">';
}

function verify_csrf(): void
{
    start_app_session();
    $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        exit('Invalid security token.');
    }
}

function json_response(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES);
    exit;
}

function read_json_body(): array
{
    $raw = file_get_contents('php://input');
    if (!$raw) {
        return [];
    }

    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function status_badge_class(string $status): string
{
    return match ($status) {
        'pending' => 'bg-amber-100 text-amber-800 border-amber-200',
        'verified', 'available', 'accepted' => 'bg-sky-100 text-sky-800 border-sky-200',
        'assigned', 'dispatched' => 'bg-indigo-100 text-indigo-800 border-indigo-200',
        'in_progress', 'arrived', 'on_scene', 'busy' => 'bg-orange-100 text-orange-800 border-orange-200',
        'resolved', 'completed', 'active' => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'rejected', 'cancelled', 'blocked' => 'bg-rose-100 text-rose-800 border-rose-200',
        'offline', 'inactive' => 'bg-slate-200 text-slate-700 border-slate-300',
        default => 'bg-slate-100 text-slate-700 border-slate-200',
    };
}

function severity_badge_class(string $severity): string
{
    return match ($severity) {
        'critical' => 'bg-red-600 text-white border-red-700',
        'high' => 'bg-orange-500 text-white border-orange-600',
        'medium' => 'bg-yellow-100 text-yellow-900 border-yellow-300',
        default => 'bg-slate-100 text-slate-700 border-slate-200',
    };
}

function report_statuses(): array
{
    return ['pending', 'verified', 'assigned', 'dispatched', 'in_progress', 'resolved', 'rejected'];
}

function assignment_statuses(): array
{
    return ['assigned', 'accepted', 'arrived', 'completed', 'cancelled'];
}
