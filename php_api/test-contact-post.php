<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$url = 'https://cpep.fr/api/contact.php';

$postData = [
    'name' => 'Test CPEP',
    'email' => 'test@example.com',
    'phone' => '0613991231',
    'company' => 'CPEP Test',
    'message' => 'Ceci est un test complet du formulaire de contact.',
    'website' => '',
    'started_at' => (string) ((int) round(microtime(true) * 1000) - 10000),
    'consent' => 'on',
];

$ch = curl_init($url);

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $postData,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HEADER => false,
    CURLOPT_TIMEOUT => 20,
]);

$response = curl_exec($ch);
$error = curl_error($ch);
$status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

echo json_encode([
    'status' => $status,
    'curl_error' => $error,
    'response' => $response,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);