<?php

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'success' => true,
    'message' => 'API CPEP OK',
    'php_version' => PHP_VERSION,
]);