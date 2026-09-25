<?php

declare(strict_types=1);

$env = static function (string $key, mixed $default = null): mixed {
    $value = $_ENV[$key] ?? getenv($key);
    return ($value === false || $value === null || $value === '') ? $default : $value;
};

return [
    'app' => [
        'name'     => $env('APP_NAME', 'Haushaltsbuch'),
        'env'      => $env('APP_ENV', 'production'),
        'debug'    => filter_var($env('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
        'base_url' => $env('APP_BASE_URL', ''),
        'timezone' => 'Europe/Berlin',
    ],
    'db' => [
        'host' => $env('DB_HOST', '127.0.0.1'),
        'port' => (int) $env('DB_PORT', 3306),
        'name' => $env('DB_NAME', 'haushaltsbuch'),
        'user' => $env('DB_USER', 'root'),
        'pass' => $env('DB_PASS', ''),
    ],
    'paths' => [
        'root'    => dirname(__DIR__),
        'views'   => dirname(__DIR__) . '/app/Views',
        'uploads' => dirname(__DIR__) . '/storage/uploads',
        'logs'    => dirname(__DIR__) . '/storage/logs',
    ],
    'ai' => [
        'api_key' => $env('ANTHROPIC_API_KEY', ''),
        'model'   => $env('ANTHROPIC_MODEL', 'claude-opus-5'),
    ],
    'upload_max_bytes' => 15 * 1024 * 1024,
];
