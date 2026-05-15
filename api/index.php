<?php

$tmpRoot = '/tmp/nexora';

foreach ([$tmpRoot, "{$tmpRoot}/cache", "{$tmpRoot}/views", "{$tmpRoot}/sessions"] as $directory) {
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
}

$serverlessDefaults = [
    'APP_CONFIG_CACHE' => "{$tmpRoot}/cache/config.php",
    'APP_EVENTS_CACHE' => "{$tmpRoot}/cache/events.php",
    'APP_PACKAGES_CACHE' => "{$tmpRoot}/cache/packages.php",
    'APP_ROUTES_CACHE' => "{$tmpRoot}/cache/routes.php",
    'APP_SERVICES_CACHE' => "{$tmpRoot}/cache/services.php",
    'CACHE_STORE' => 'array',
    'LOG_CHANNEL' => 'stderr',
    'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array',
    'VIEW_COMPILED_PATH' => "{$tmpRoot}/views",
];

foreach ($serverlessDefaults as $key => $value) {
    if (getenv($key) !== false) {
        continue;
    }

    putenv("{$key}={$value}");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

try {
    require __DIR__.'/../public/index.php';
} catch (Throwable $error) {
    error_log($error);

    http_response_code(500);
    header('Content-Type: application/json');

    $debug = filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOL);
    echo json_encode($debug
        ? [
            'error' => $error->getMessage(),
            'type' => $error::class,
            'file' => $error->getFile(),
            'line' => $error->getLine(),
        ]
        : ['error' => 'Internal Server Error']
    );
}
