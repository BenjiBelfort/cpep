<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$configPath = __DIR__ . '/../../private/config.local.php';

$result = [
    'php_ok' => true,
    'php_version' => PHP_VERSION,
    'config_path' => $configPath,
    'config_exists' => file_exists($configPath),
    'mailer_exists' => file_exists(__DIR__ . '/mailer.php'),
    'autoload_exists' => file_exists(__DIR__ . '/vendor/autoload.php'),
    'mb_strlen_exists' => function_exists('mb_strlen'),
];

if (file_exists($configPath)) {
    $config = require $configPath;

    $rateLimitDir = $config['ratelimit_dir'] ?? (__DIR__ . '/var/ratelimit');

    $result['ratelimit_dir'] = $rateLimitDir;
    $result['ratelimit_dir_exists_before'] = is_dir($rateLimitDir);

    if (!is_dir($rateLimitDir)) {
        $result['ratelimit_mkdir_result'] = @mkdir($rateLimitDir, 0755, true);
    }

    $result['ratelimit_dir_exists_after'] = is_dir($rateLimitDir);
    $result['ratelimit_is_writable'] = is_writable($rateLimitDir);

    $testFile = rtrim($rateLimitDir, '/') . '/test-write.json';
    $writeResult = @file_put_contents($testFile, json_encode(['ok' => true, 'time' => time()]));

    $result['ratelimit_test_write'] = $writeResult !== false;

    if (file_exists($testFile)) {
        @unlink($testFile);
    }
}

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);