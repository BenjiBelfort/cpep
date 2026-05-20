<?php

declare(strict_types=1);

final class SeoMicroAudit
{
    public static function normalizeUrl(string $input): string
    {
        $input = trim($input);

        if (!preg_match('#^https?://#i', $input)) {
            $input = 'https://' . $input;
        }

        return $input;
    }

    public static function isAllowedPublicUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = (string) parse_url($url, PHP_URL_HOST);

        if (!in_array($scheme, ['http', 'https'], true)) {
            return false;
        }

        if (!$host) {
            return false;
        }

        return self::hostResolvesToPublicIps($host);
    }

    private static function hostResolvesToPublicIps(string $host): bool
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

    public static function fetchUrlSafe(string $url, int $maxRedirects = 3, int $maxBytes = 2000000): array
    {
        $currentUrl = $url;
        $redirects = 0;

        while ($redirects <= $maxRedirects) {
            if (!self::isAllowedPublicUrl($currentUrl)) {
                return [
                    'success' => false,
                    'error' => 'URL refusée pour des raisons de sécurité.',
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
                CURLOPT_USERAGENT => 'CPEP Diagnostic Visibilite Bot/1.0',
                CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
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

            $error = curl_error($ch);
            $errno = curl_errno($ch);
            $info = curl_getinfo($ch);

            if ($tooLarge) {
                return [
                    'success' => false,
                    'error' => 'La page est trop lourde pour cette première analyse.',
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
                    'error' => 'La ressource analysée ne semble pas être une page HTML.',
                ];
            }

            return [
                'success' => true,
                'status' => $status,
                'final_url' => $currentUrl,
                'content_type' => $contentType,
                'load_time' => $duration,
                'body' => $body,
                'headers' => $headers,
                'redirects' => $redirects,
            ];
        }

        return [
            'success' => false,
            'error' => 'Trop de redirections.',
        ];
    }

    public static function analyze(string $url): array
    {
        $url = self::normalizeUrl($url);

        if (!self::isAllowedPublicUrl($url)) {
            return [
                'success' => false,
                'error' => 'URL invalide ou refusée pour des raisons de sécurité.',
            ];
        }

        $fetch = self::fetchUrlSafe($url);

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
        $canonicalNodes = $xpath->query('//link[translate(@rel, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="canonical"]');
        $viewportNodes = $xpath->query('//meta[translate(@name, "ABCDEFGHIJKLMNOPQRSTUVWXYZ", "abcdefghijklmnopqrstuvwxyz")="viewport"]');
        $imageNodes = $xpath->query('//img');
        $ogTitleNodes = $xpath->query('//meta[@property="og:title"]');
        $ogDescriptionNodes = $xpath->query('//meta[@property="og:description"]');
        $ogImageNodes = $xpath->query('//meta[@property="og:image"]');

        $title = $titleNodes->length > 0 ? trim($titleNodes->item(0)->textContent) : '';
        $description = $descriptionNodes->length > 0 ? trim($descriptionNodes->item(0)->getAttribute('content')) : '';

        $imagesWithoutAlt = 0;

        foreach ($imageNodes as $img) {
            if (!$img->hasAttribute('alt')) {
                $imagesWithoutAlt++;
            }
        }

        $score = 0;
        $strengths = [];
        $recommendations = [];

        $titleLength = mb_strlen($title);
        $descriptionLength = mb_strlen($description);

        if ($fetch['status'] === 200) {
            $score += 10;
            $strengths[] = 'Le site répond correctement.';
        }

        if (str_starts_with($fetch['final_url'], 'https://')) {
            $score += 10;
            $strengths[] = 'Le site utilise HTTPS.';
        } else {
            $recommendations[] = 'Le site devrait être accessible en HTTPS.';
        }

        if ($titleLength >= 30 && $titleLength <= 65) {
            $score += 15;
            $strengths[] = 'La balise title est présente et sa longueur est correcte.';
        } elseif ($titleLength > 0) {
            $score += 8;
            $recommendations[] = 'La balise title est présente, mais sa longueur pourrait être optimisée.';
        } else {
            $recommendations[] = 'Aucune balise title détectée.';
        }

        if ($descriptionLength >= 70 && $descriptionLength <= 160) {
            $score += 15;
            $strengths[] = 'La meta description est présente et bien dimensionnée.';
        } elseif ($descriptionLength > 0) {
            $score += 8;
            $recommendations[] = 'La meta description est présente, mais sa longueur pourrait être améliorée.';
        } else {
            $recommendations[] = 'Aucune meta description détectée.';
        }

        if ($h1Nodes->length === 1) {
            $score += 10;
            $strengths[] = 'La page contient un seul H1.';
        } elseif ($h1Nodes->length === 0) {
            $recommendations[] = 'Aucun H1 détecté sur la page.';
        } else {
            $score += 5;
            $recommendations[] = 'Plusieurs H1 détectés. La hiérarchie de titres mérite une vérification.';
        }

        if ($canonicalNodes->length > 0) {
            $score += 10;
            $strengths[] = 'Une balise canonical est présente.';
        } else {
            $recommendations[] = 'Aucune balise canonical détectée.';
        }

        if ($viewportNodes->length > 0) {
            $score += 10;
            $strengths[] = 'La balise viewport est présente pour l’affichage mobile.';
        } else {
            $recommendations[] = 'Aucune balise viewport détectée.';
        }

        if ($imageNodes->length > 0 && $imagesWithoutAlt === 0) {
            $score += 10;
            $strengths[] = 'Les images possèdent toutes un attribut alt.';
        } elseif ($imageNodes->length > 0) {
            $score += 4;
            $recommendations[] = $imagesWithoutAlt . ' image(s) ne possèdent pas d’attribut alt.';
        }

        if ($ogTitleNodes->length > 0 && $ogDescriptionNodes->length > 0 && $ogImageNodes->length > 0) {
            $score += 10;
            $strengths[] = 'Les principales balises Open Graph sont présentes.';
        } else {
            $recommendations[] = 'Certaines balises Open Graph sont absentes.';
        }

        return [
            'success' => true,
            'url_testee' => $url,
            'url_finale' => $fetch['final_url'],
            'status_http' => $fetch['status'],
            'temps_chargement' => round($fetch['load_time'], 2) . 's',
            'poids_html' => round(strlen($fetch['body']) / 1024, 1) . ' Ko',
            'redirections' => $fetch['redirects'],
            'score' => min($score, 100),
            'title' => [
                'valeur' => $title,
                'longueur' => $titleLength,
                'ok' => $titleLength >= 30 && $titleLength <= 65,
            ],
            'meta_description' => [
                'valeur' => $description,
                'longueur' => $descriptionLength,
                'ok' => $descriptionLength >= 70 && $descriptionLength <= 160,
            ],
            'h1' => [
                'nombre' => $h1Nodes->length,
                'ok' => $h1Nodes->length === 1,
            ],
            'canonical' => [
                'presente' => $canonicalNodes->length > 0,
            ],
            'viewport' => [
                'presente' => $viewportNodes->length > 0,
            ],
            'images' => [
                'nombre_total' => $imageNodes->length,
                'sans_alt' => $imagesWithoutAlt,
            ],
            'open_graph' => [
                'og_title' => $ogTitleNodes->length > 0,
                'og_description' => $ogDescriptionNodes->length > 0,
                'og_image' => $ogImageNodes->length > 0,
            ],
            'points_forts' => $strengths,
            'recommandations' => $recommendations,
        ];
    }
}