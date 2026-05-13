<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$configPath = __DIR__ . '/../../private/config.local.php';

if (!file_exists($configPath)) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Config introuvable',
    ]);
    exit;
}

$config = require $configPath;

require __DIR__ . '/mailer.php';

$sent = sendMail(
    $config,
    $config['mail_to'],
    $config['mail_to_name'],
    'Test SMTP CPEP',
    '<p>Test SMTP depuis cpep.fr</p>',
    'Test SMTP depuis cpep.fr'
);

echo json_encode([
    'success' => $sent,
    'message' => $sent ? 'Mail envoyé' : 'Échec envoi mail, vérifier les logs serveur',
]);