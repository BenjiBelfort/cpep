<?php

// à mettre dans private/seo-audit/MicroDiagnostic.php

declare(strict_types=1);

final class MicroDiagnostic
{
    public static function run(string $inputUrl): array
    {
        $url = self::normalizeUrl($inputUrl);

        if (!self::isAllowedPublicUrl($url)) {
            return [
                'success' => false,
                'error' => 'URL invalide ou refusée pour des raisons de sécurité.',
            ];
        }

        $fetch = self::fetchHtml($url);

        if (!$fetch['success']) {
            return $fetch;
        }

        libxml_use_internal_errors(true);

        $dom = new DOMDocument();
        $dom->loadHTML($fetch['body']);

        libxml_clear_errors();

        $xpath = new DOMXPath($dom);

        $titleNodes = $xpath->query('//title');
        $descriptionNodes = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="description"]');
        $h1Nodes = $xpath->query('//h1');
        $imageNodes = $xpath->query('//img');

        $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';
        $description = $descriptionNodes->length > 0 ? trim($descriptionNodes->item(0)->getAttribute('content')) : '';

        $imagesWithoutAlt = 0;

        foreach ($imageNodes as $img) {
            if (!$img->hasAttribute('alt')) {
                $imagesWithoutAlt++;
            }
        }

        $score = 0;
        $recommendations = [];

        if ($fetch['status'] === 200) {
            $score += 20;
        }

        if (str_starts_with($fetch['final_url'], 'https://')) {
            $score += 20;
        } else {
            $recommendations[] = 'Le site devrait utiliser HTTPS.';
        }

        if (mb_strlen($title) >= 30 && mb_strlen($title) <= 65) {
            $score += 20;
        } else {
            $recommendations[] = 'La balise title est absente ou sa longueur pourrait être optimisée.';
        }

        if (mb_strlen($description) >= 70 && mb_strlen($description) <= 160) {
            $score += 20;
        } else {
            $recommendations[] = 'La meta description est absente ou sa longueur pourrait être améliorée.';
        }

        if ($h1Nodes->length === 1) {
            $score += 10;
        } else {
            $recommendations[] = 'La page devrait contenir un seul H1 principal.';
        }

        if ($imageNodes->length > 0 && $imagesWithoutAlt === 0) {
            $score += 10;
        } elseif ($imageNodes->length > 0) {
            $recommendations[] = $imagesWithoutAlt . ' image(s) sans attribut alt détectée(s).';
        }

        return [
            'success' => true,
            'url_initiale' => $url,
            'url_finale' => $fetch['final_url'],
            'status_http' => $fetch['status'],
            'temps_chargement' => round($fetch['load_time'], 2) . 's',
            'poids_html' => round(strlen($fetch['body']) / 1024, 1) . ' Ko',
            'score' => min($score, 100),
            'title' => [
                'valeur' => $title,
                'longueur' => mb_strlen($title),
            ],
            'meta_description' => [
                'valeur' => $description,
                'longueur' => mb_strlen($description),
            ],
            'h1' => [
                'nombre' => $h1Nodes->length,
            ],
            'images' => [
                'nombre_total' => $imageNodes->length,
                'sans_alt' => $imagesWithoutAlt,
            ],
            'recommandations' => $recommendations,
        ];
    }

    private static function normalizeUrl(string $input): string
    {
        $input = trim($input);

        if (!preg_match('#^https?://#i', $input)) {
            $input = 'https://' . $input;
        }

        return $input;
    }

    private static function isAllowedPublicUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if ($host === '') {
            return false;
        }

        return self::hostResolvesToPublicIp($host);
    }

    private static function hostResolvesToPublicIp(string $host): bool
    {
        $host = strtolower(trim($host));

        if ($host === 'localhost' || str_ends_with($host, '.localhost')) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return self::isPublicIp($host);
        }

        $records = dns_get_record($host, DNS_A + DNS_AAAA);

        if (!$records) {
            return false;
        }

        foreach ($records as $record) {
            $ip = $record['ip'] ?? $record['ipv6'] ?? null;

            if (!$ip || !self::isPublicIp($ip)) {
                return false;
            }
        }

        return true;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }

    private static function fetchHtml(string $url, int $maxRedirects = 3, int $maxBytes = 1000000): array
    {
        $currentUrl = $url;
        $redirects = 0;

        while ($redirects <= $maxRedirects) {
            if (!self::isAllowedPublicUrl($currentUrl)) {
                return [
                    'success' => false,
                    'error' => 'Redirection refusée pour des raisons de sécurité.',
                ];
            }

            $body = '';
            $headers = '';
            $tooLarge = false;

            $ch = curl_init($currentUrl);

            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_USERAGENT => 'CPEP Micro Diagnostic Bot/1.0',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,

                CURLOPT_HEADERFUNCTION => function ($curl, string $headerLine) use (&$headers): int {
                    $headers .= $headerLine;
                    return strlen($headerLine);
                },

                CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$body, $maxBytes, &$tooLarge): int {
                    if (strlen($body) + strlen($chunk) > $maxBytes) {
                        $tooLarge = true;
                        return 0;
                    }

                    $body .= $chunk;
                    return strlen($chunk);
                },
            ]);

            $start = microtime(true);
            curl_exec($ch);
            $duration = microtime(true) - $start;

            $errno = curl_errno($ch);
            $error = curl_error($ch);
            $info = curl_getinfo($ch);

            if ($tooLarge) {
                return [
                    'success' => false,
                    'error' => 'Page trop lourde pour cette première analyse.',
                ];
            }

            if ($errno !== 0) {
                return [
                    'success' => false,
                    'error' => $error ?: 'Erreur lors de la récupération de la page.',
                ];
            }

            $status = (int) ($info['http_code'] ?? 0);
            $contentType = strtolower((string) ($info['content_type'] ?? ''));

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                if (!preg_match('/^Location:\s*(.+)$/mi', $headers, $matches)) {
                    return [
                        'success' => false,
                        'error' => 'Redirection sans destination.',
                    ];
                }

                $location = trim($matches[1]);

                if (str_starts_with($location, '/')) {
                    $parts = parse_url($currentUrl);
                    $location = $parts['scheme'] . '://' . $parts['host'] . $location;
                }

                $currentUrl = $location;
                $redirects++;

                continue;
            }

            if ($status < 200 || $status >= 400) {
                return [
                    'success' => false,
                    'error' => 'Le site répond avec un statut HTTP ' . $status . '.',
                ];
            }

            if (!str_contains($contentType, 'text/html')) {
                return [
                    'success' => false,
                    'error' => 'La ressource ne semble pas être une page HTML.',
                ];
            }

            return [
                'success' => true,
                'status' => $status,
                'final_url' => $currentUrl,
                'load_time' => $duration,
                'body' => $body,
            ];
        }

        return [
            'success' => false,
            'error' => 'Trop de redirections.',
        ];
    }
}