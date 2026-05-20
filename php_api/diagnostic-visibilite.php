<?php

// à mettre dans www/api/diagnostic-visibilite.php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require __DIR__ . '/vendor/autoload.php';

function jsonResponse(bool $success, string $message, int $status = 200, array $extra = []): void
{
    http_response_code($status);

    echo json_encode(
        array_merge([
            'success' => $success,
            'message' => $message,
        ], $extra),
        JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT
    );

    exit;
}

function cleanText(?string $value, int $maxLength = 300): string
{
    $value = trim((string) $value);
    $value = strip_tags($value);
    $value = preg_replace('/\s+/', ' ', $value) ?? '';

    return mb_substr($value, 0, $maxLength);
}

function checkRateLimit(string $ip, string $dir, int $maxAttempts, int $windowSeconds): bool
{
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $file = rtrim($dir, '/') . '/' . hash('sha256', $ip) . '.json';
    $now = time();
    $attempts = [];

    if (file_exists($file)) {
        $content = file_get_contents($file);
        $attempts = json_decode($content ?: '[]', true);

        if (!is_array($attempts)) {
            $attempts = [];
        }
    }

    $attempts = array_filter(
        $attempts,
        fn ($timestamp) => is_int($timestamp) && $timestamp > ($now - $windowSeconds)
    );

    if (count($attempts) >= $maxAttempts) {
        return false;
    }

    $attempts[] = $now;

    file_put_contents($file, json_encode(array_values($attempts)));

    return true;
}

function buildReport(string $company, array $audit): string
{
    $lines = [];

    $lines[] = 'Bonjour,';
    $lines[] = '';
    $lines[] = 'Voici votre premier diagnostic de visibilité web.';
    $lines[] = '';
    $lines[] = 'Site analysé : ' . $audit['url_finale'];
    $lines[] = 'Score provisoire : ' . $audit['score'] . ' / 100';
    $lines[] = 'Temps de chargement : ' . $audit['temps_chargement'];
    $lines[] = 'Poids HTML : ' . $audit['poids_html'];
    $lines[] = '';

    if ($company !== '') {
        $lines[] = 'Entreprise : ' . $company;
        $lines[] = '';
    }

    $lines[] = 'Données détectées :';
    $lines[] = '- Title : ' . ($audit['title']['valeur'] ?: 'absent') . ' (' . $audit['title']['longueur'] . ' caractères)';
    $lines[] = '- Meta description : ' . ($audit['meta_description']['valeur'] ?: 'absente') . ' (' . $audit['meta_description']['longueur'] . ' caractères)';
    $lines[] = '- H1 : ' . $audit['h1']['nombre'];
    $lines[] = '- Images : ' . $audit['images']['nombre_total'] . ', dont ' . $audit['images']['sans_alt'] . ' sans attribut alt';
    $lines[] = '';

    $lines[] = 'Points à vérifier :';

    if (!empty($audit['recommandations'])) {
        foreach ($audit['recommandations'] as $item) {
            $lines[] = '- ' . $item;
        }
    } else {
        $lines[] = '- Aucun point bloquant évident détecté dans cette première analyse.';
    }

    $lines[] = '';
    $lines[] = 'Ce diagnostic est volontairement simple pour ce premier test.';
    $lines[] = 'Benjamin — CPEP';
    $lines[] = 'https://www.cpep.fr';

    return implode("\n", $lines);
}

function sendDiagnosticMail(array $config, string $toEmail, string $company, array $audit): void
{
    $mail = new PHPMailer(true);

    $debugLog = __DIR__ . '/../../private/ratelimit/logs/diagnostic-smtp-debug.log';

    $mail->isSMTP();
    $mail->Host = $config['smtp_host'];
    $mail->SMTPAuth = true;
    $mail->Username = $config['smtp_user'];
    $mail->Password = $config['smtp_pass'];
    $mail->SMTPSecure = $config['smtp_secure'];
    $mail->Port = (int) $config['smtp_port'];
    $mail->CharSet = 'UTF-8';

    // Important avec certains SMTP : envelope sender
    $mail->Sender = $config['mail_from'];

    $mail->setFrom($config['mail_from'], $config['mail_from_name']);
    $mail->addAddress($toEmail);

    if (
        !empty($config['mail_to']) &&
        strtolower($config['mail_to']) !== strtolower($toEmail)
    ) {
        $mail->addBCC($config['mail_to'], $config['mail_to_name'] ?? 'CPEP');
    }

    $mail->addReplyTo($config['mail_from'], $config['mail_from_name']);

    $mail->isHTML(false);
    $mail->Subject = 'Votre diagnostic visibilité web - CPEP';
    $mail->Body = buildReport($company, $audit);
    $mail->AltBody = $mail->Body;

    $mail->send();
}

$configPath = __DIR__ . '/../../private/config.local.php';
$enginePath = __DIR__ . '/../../private/seo-audit/MicroDiagnostic.php';

if (!file_exists($configPath)) {
    jsonResponse(false, 'Configuration serveur introuvable.', 500);
}

if (!file_exists($enginePath)) {
    jsonResponse(false, 'Moteur de diagnostic introuvable.', 500);
}

$config = require $configPath;
require $enginePath;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(false, 'Méthode non autorisée.', 405);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';

$rateLimitDir = rtrim($config['ratelimit_dir'], '/') . '/diagnostic';

if (!checkRateLimit(
    $ip,
    $rateLimitDir,
    (int) $config['rate_limit_max'],
    (int) $config['rate_limit_window']
)) {
    jsonResponse(false, 'Trop de demandes. Merci de réessayer plus tard.', 429);
}

$honeypot = cleanText($_POST['website'] ?? '', 200);

if ($honeypot !== '') {
    jsonResponse(true, 'Merci, votre demande a bien été prise en compte.');
}

$startedAt = (int) ($_POST['started_at'] ?? 0);
$nowMs = (int) round(microtime(true) * 1000);

$elapsedSeconds = $startedAt > 0 ? (($nowMs - $startedAt) / 1000) : 0;

if ($startedAt <= 0 || $elapsedSeconds < (int) $config['min_submit_time']) {
    jsonResponse(false, 'Soumission trop rapide. Merci de réessayer.', 400);
}

if ($elapsedSeconds > (int) $config['max_submit_time']) {
    jsonResponse(false, 'Le formulaire a expiré. Merci de recharger la page.', 400);
}

$url = cleanText($_POST['url'] ?? '', 500);
$email = cleanText($_POST['email'] ?? '', (int) $config['max_email_length']);
$company = cleanText($_POST['company'] ?? '', (int) $config['max_company_length']);
$consent = isset($_POST['consent']);

if (!$consent) {
    jsonResponse(false, 'Le consentement est obligatoire pour envoyer le diagnostic.', 400);
}

if ($url === '') {
    jsonResponse(false, 'Merci d’indiquer le site à analyser.', 400);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    jsonResponse(false, 'Adresse email invalide.', 400);
}

$audit = MicroDiagnostic::run($url);

if (!($audit['success'] ?? false)) {
    jsonResponse(false, $audit['error'] ?? 'Impossible d’analyser cette URL.', 400);
}

try {
    sendDiagnosticMail($config, $email, $company, $audit);
} catch (Exception $e) {
    error_log('Erreur diagnostic visibilité : ' . $e->getMessage());

    jsonResponse(false, 'Le diagnostic a été généré, mais l’email n’a pas pu être envoyé.', 500);
}

jsonResponse(true, 'Votre diagnostic a bien été envoyé par email.', 200, [
    'score' => $audit['score'],
    'url_finale' => $audit['url_finale'],
]);