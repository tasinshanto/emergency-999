<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Dhaka');

function env_value(string $key, string $default): string
{
    $value = $_ENV[$key] ?? getenv($key);
    return $value === false || $value === null || $value === '' ? $default : (string) $value;
}

define('APP_NAME', 'Emergency 999');
define('DB_HOST', env_value('DB_HOST', '127.0.0.1'));
define('DB_NAME', env_value('DB_NAME', 'emergency_999_db'));
define('DB_USER', env_value('DB_USER', 'root'));
define('DB_PASS', env_value('DB_PASS', ''));
define('DB_CHARSET', 'utf8mb4');

// Leave blank for auto-detection from the request. Set to "/your-folder" if auto-detect fails.
define('BASE_URL', env_value('BASE_URL', ''));

/**
 * Urban first-response dispatch radius (km). ~12 km ≈ 15–25 min by emergency vehicle in dense cities.
 * Used for auto-dispatch, nearest-unit suggestions, and responder self-dispatch eligibility.
 */
define('DISPATCH_RANGE_KM', (float) env_value('DISPATCH_RANGE_KM', '12'));
