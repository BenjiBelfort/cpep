<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'success' => false,
        'message' => 'Méthode non autorisée.'
    ]);
    exit;
}

$configPaths = [
    __DIR__ . '/../../private/config.local.php', // prod OVH
    __DIR__ . '/config.local.php',               // local
];

$config = null;

foreach ($configPaths as $path) {
    if (file_exists($path)) {
        $config = require $path;
        break;
    }
}

if (!$config) {
    error_log('Config contact introuvable.');
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Configuration serveur manquante.'
    ]);
    exit;
}

require __DIR__ . '/mailer.php';

function cleanInput(?string $value): string
{
    return trim(strip_tags((string) $value));
}

function isValidEmail(string $email): bool
{
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

function tooLong(string $value, int $max): bool
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8') > $max;
    }

    return strlen($value) > $max;
}

function countLinks(string $text): int
{
    preg_match_all('/https?:\/\/|www\.|[a-z0-9-]+\.[a-z]{2,}/i', $text, $matches);
    return count($matches[0]);
}

function getClientIp(): string
{
    return $_SERVER['REMOTE_ADDR'] ?? 'unknown';
}

function checkRateLimit(array $config): bool
{
    $dir = $config['ratelimit_dir'] ?? (__DIR__ . '/../../private/ratelimit');

    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        error_log('Impossible de créer le dossier rate limit : ' . $dir);
        return false;
    }

    if (!is_writable($dir)) {
        error_log('Dossier rate limit non inscriptible : ' . $dir);
        return false;
    }

    $ip = getClientIp();
    $hash = hash('sha256', $ip);
    $file = rtrim($dir, '/') . '/' . $hash . '.json';

    $now = time();
    $window = (int) ($config['rate_limit_window'] ?? 3600);
    $max = (int) ($config['rate_limit_max'] ?? 5);

    $attempts = [];

    if (file_exists($file)) {
        $content = file_get_contents($file);
        $decoded = json_decode($content ?: '[]', true);

        if (is_array($decoded)) {
            $attempts = $decoded;
        }
    }

    $attempts = array_values(array_filter(
        $attempts,
        fn ($timestamp) => is_int($timestamp) && $timestamp > ($now - $window)
    ));

    if (count($attempts) >= $max) {
        return false;
    }

    $attempts[] = $now;

    $written = file_put_contents($file, json_encode($attempts), LOCK_EX);

    if ($written === false) {
        error_log('Impossible d’écrire le fichier rate limit : ' . $file);
        return false;
    }

    return true;
}

$name = cleanInput($_POST['name'] ?? '');
$email = cleanInput($_POST['email'] ?? '');
$phone = cleanInput($_POST['phone'] ?? '');
$company = cleanInput($_POST['company'] ?? '');
$message = cleanInput($_POST['message'] ?? '');
$website = cleanInput($_POST['website'] ?? '');
$consent = isset($_POST['consent']);

$startedAt = isset($_POST['started_at']) ? (int) $_POST['started_at'] : 0;

// Honeypot : si rempli, robot probable.
// On répond success pour ne pas aider le robot à comprendre.
if ($website !== '') {
    echo json_encode([
        'success' => true,
        'message' => 'Merci, votre message a bien été envoyé.'
    ]);
    exit;
}

// Anti-spam temps minimum / maximum.
// Le front envoie Date.now(), donc en millisecondes.
$nowMs = (int) round(microtime(true) * 1000);
$elapsedSeconds = $startedAt > 0 ? ($nowMs - $startedAt) / 1000 : 0;

$minSubmitTime = (int) ($config['min_submit_time'] ?? 3);
$maxSubmitTime = (int) ($config['max_submit_time'] ?? 3600);

if ($elapsedSeconds > 0 && $elapsedSeconds < $minSubmitTime) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Le formulaire a été envoyé trop rapidement.'
    ]);
    exit;
}

if ($elapsedSeconds > $maxSubmitTime) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => 'Le formulaire a expiré.'
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

if (!$consent) {
    $errors[] = 'Le consentement est obligatoire.';
}

if (
    tooLong($name, (int) ($config['max_name_length'] ?? 120)) ||
    tooLong($email, (int) ($config['max_email_length'] ?? 180)) ||
    tooLong($phone, (int) ($config['max_phone_length'] ?? 40)) ||
    tooLong($company, (int) ($config['max_company_length'] ?? 160)) ||
    tooLong($message, (int) ($config['max_message_length'] ?? 4000))
) {
    $errors[] = 'Certains champs sont trop longs.';
}

if (countLinks($message) > (int) ($config['max_links_in_message'] ?? 2)) {
    $errors[] = 'Le message contient trop de liens.';
}

if (!empty($errors)) {
    http_response_code(422);
    echo json_encode([
        'success' => false,
        'message' => implode(' ', $errors)
    ]);
    exit;
}

// Rate limit IP
if (!checkRateLimit($config)) {
    http_response_code(429);
    echo json_encode([
        'success' => false,
        'message' => 'Trop de tentatives. Merci de réessayer plus tard.'
    ]);
    exit;
}

// Sécurisation affichage HTML
$safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
$safeEmail = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');
$safePhone = htmlspecialchars($phone ?: 'Non renseigné', ENT_QUOTES, 'UTF-8');
$safeCompany = htmlspecialchars($company ?: 'Non renseignée', ENT_QUOTES, 'UTF-8');
$safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8'));

$adminSubject = 'Nouveau message depuis le site CPEP';

$adminHtml = "
    <h1>Nouveau message depuis cpep.fr</h1>

    <p><strong>Nom :</strong> {$safeName}</p>
    <p><strong>Email :</strong> {$safeEmail}</p>
    <p><strong>Téléphone :</strong> {$safePhone}</p>
    <p><strong>Société / structure :</strong> {$safeCompany}</p>

    <hr>

    <p><strong>Message :</strong></p>
    <p>{$safeMessage}</p>
";

$adminText = "
Nouveau message depuis cpep.fr

Nom : {$name}
Email : {$email}
Téléphone : " . ($phone ?: 'Non renseigné') . "
Société / structure : " . ($company ?: 'Non renseignée') . "

Message :
{$message}
";

$clientSubject = 'Votre message a bien été reçu — CPEP';

$clientHtml = "
    <h1>Merci pour votre message</h1>

    <p>Bonjour {$safeName},</p>

    <p>Votre message a bien été reçu. Nous revenons vers vous dès que possible avec une réponse claire et utile.</p>

    <p><strong>Résumé de votre demande :</strong></p>

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

try {
    // 1. Email vers CPEP : celui-ci est indispensable.
    $replyToEmail = isValidEmail($email) ? $email : null;
    $replyToName = $name !== '' ? $name : $email;

    $adminSent = sendMail(
        $config,
        $config['mail_to'],
        $config['mail_to_name'],
        $adminSubject,
        $adminHtml,
        $adminText,
        $replyToEmail,
        $replyToName
    );

    if (!$adminSent) {
        throw new RuntimeException('Échec de l’envoi admin. Vérifier les logs PHPMailer.');
    }

    // 2. Confirmation client : utile, mais ne doit pas bloquer la demande.
    try {
        $clientSent = sendMail(
            $config,
            $email,
            $name,
            $clientSubject,
            $clientHtml,
            $clientText
        );

        if (!$clientSent) {
            error_log('Confirmation client non envoyée à : ' . $email);
        }
    } catch (Throwable $clientError) {
        error_log('Erreur confirmation client : ' . $clientError->getMessage());
    }

    echo json_encode([
        'success' => true,
        'message' => 'Merci, votre message a bien été envoyé.'
    ]);
} catch (Throwable $e) {
    error_log('Erreur formulaire contact CPEP : ' . $e->getMessage());
    error_log('Fichier : ' . $e->getFile());
    error_log('Ligne : ' . $e->getLine());
    error_log('HTTP_HOST : ' . ($_SERVER['HTTP_HOST'] ?? 'unknown'));
    error_log('Email testé : ' . $email);

    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Une erreur est survenue lors de l’envoi du message.'
    ]);
}