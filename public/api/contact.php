<?php

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Méthode non autorisée.'
    ]);
    exit;
}

$config = require __DIR__ . '/config.php';
require __DIR__ . '/mailer.php';

function cleanInput(string $value): string
{
    return trim(strip_tags($value));
}

function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

$name = cleanInput($_POST['name'] ?? '');
$email = cleanInput($_POST['email'] ?? '');
$phone = cleanInput($_POST['phone'] ?? '');
$projectType = cleanInput($_POST['projectType'] ?? '');
$message = cleanInput($_POST['message'] ?? '');
$website = cleanInput($_POST['website'] ?? '');
$startedAt = (int) ($_POST['startedAt'] ?? 0);

// Honeypot : si rempli, robot probable
if ($website !== '') {
    echo json_encode([
        'success' => true,
        'message' => 'Merci, votre message a bien été envoyé.'
    ]);
    exit;
}

// Anti-spam temps minimum
$now = time();
if ($startedAt > 0 && ($now - $startedAt) < $config['min_submit_time']) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Le formulaire a été envoyé trop rapidement.'
    ]);
    exit;
}

// Validation
$errors = [];

if ($name === '') {
    $errors[] = 'Le nom est obligatoire.';
}

if (!isValidEmail($email)) {
    $errors[] = 'L’adresse email est invalide.';
}

if ($message === '') {
    $errors[] = 'Le message est obligatoire.';
}

if (strlen($message) > 5000) {
    $errors[] = 'Le message est trop long.';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => implode(' ', $errors)
    ]);
    exit;
}

// Sécurisation affichage HTML
$safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safePhone = htmlspecialchars($phone, ENT_QUOTES, 'UTF-8');
$safeProjectType = htmlspecialchars($projectType, ENT_QUOTES, 'UTF-8');
$safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

$adminSubject = 'Nouveau message depuis le site CPEP';

$adminHtml = "
    <h1>Nouveau message depuis cpep.fr</h1>

    <p><strong>Nom :</strong> {$safeName}</p>
    <p><strong>Email :</strong> {$safeEmail}</p>
    <p><strong>Téléphone :</strong> {$safePhone}</p>
    <p><strong>Type de projet :</strong> {$safeProjectType}</p>

    <hr>

    <p><strong>Message :</strong></p>
    <p>{$safeMessage}</p>
";

$adminText = "
Nouveau message depuis cpep.fr

Nom : {$name}
Email : {$email}
Téléphone : {$phone}
Type de projet : {$projectType}

Message :
{$message}
";

$clientSubject = 'Votre message a bien été reçu — CPEP';

$clientHtml = "
    <h1>Merci pour votre message</h1>

    <p>Bonjour {$safeName},</p>

    <p>Votre message a bien été reçu. Nous revenons vers vous dès que possible avec une réponse claire et utile.</p>

    <p>Résumé de votre demande :</p>

    <blockquote>{$safeMessage}</blockquote>

    <p>À bientôt,<br>L’équipe CPEP</p>
";

$clientText = "
Bonjour {$name},

Votre message a bien été reçu. Nous revenons vers vous dès que possible avec une réponse claire et utile.

Résumé de votre demande :

{$message}

À bientôt,
L’équipe CPEP
";

// 1. Email vers CPEP
$adminSent = sendMail(
    $config,
    $config['mail_to'],
    $config['mail_to_name'],
    $adminSubject,
    $adminHtml,
    $adminText,
    $email,
    $name
);

// 2. Confirmation client
$clientSent = sendMail(
    $config,
    $email,
    $name,
    $clientSubject,
    $clientHtml,
    $clientText
);

if (!$adminSent) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Une erreur est survenue lors de l’envoi du message.'
    ]);
    exit;
}

echo json_encode([
    'success' => true,
    'message' => 'Merci, votre message a bien été envoyé.'
]);