<?php
declare(strict_types=1);

namespace SimpleCommerce\Support;

use SimpleCommerce\Adapters\AdapterError;

/**
 * Appels HTTP sortants (cURL) avec délai maximal, sans suivre les redirections,
 * et traduction des erreurs en messages compréhensibles.
 */
final class Http
{
    /**
     * @param array<string, string> $headers
     * @param string|array|null $body tableau = formulaire multipart
     * @return array{status: int, body: string, headers: array<string, string>}
     */
    public static function request(string $method, string $url, array $headers = [], string|array|null $body = null, int $timeout = 25): array
    {
        $ch = curl_init($url);
        $responseHeaders = [];
        $h = [];
        foreach ($headers as $k => $v) {
            $h[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $h,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_USERAGENT => 'SimpleCommerce/1.0',
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$responseHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new AdapterError('network', 'Impossible de joindre le service. Vérifiez l\'adresse, puis réessayez.', $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return ['status' => $status, 'body' => (string) $out, 'headers' => $responseHeaders];
    }

    /** Requête JSON : lève une erreur lisible si le statut n'est pas 2xx. */
    public static function json(string $service, string $method, string $url, array $headers = [], mixed $body = null, int $timeout = 25): array
    {
        if (is_array($body) && !isset($headers['Content-Type'])) {
            $headers['Content-Type'] = 'application/json';
            $body = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $headers += ['Accept' => 'application/json'];
        $res = self::request($method, $url, $headers, $body, $timeout);
        if ($res['status'] >= 300 && $res['status'] < 400) {
            throw new AdapterError('invalid', "$service redirige vers une autre adresse : vérifiez l'adresse exacte (avec ou sans « www », en https).", 'HTTP ' . $res['status']);
        }
        if ($res['status'] >= 400) {
            throw self::error($service, $res['status'], $res['body']);
        }
        $data = $res['body'] === '' ? null : json_decode($res['body'], true);
        if ($res['body'] !== '' && json_last_error() !== JSON_ERROR_NONE) {
            throw new AdapterError('remote', "$service a renvoyé une réponse illisible.", substr($res['body'], 0, 200));
        }
        return ['data' => $data, 'headers' => $res['headers'], 'status' => $res['status']];
    }

    public static function error(string $service, int $status, string $body): AdapterError
    {
        $detail = "HTTP $status " . substr($body, 0, 300);
        return match (true) {
            $status === 401 => new AdapterError('auth', "$service refuse les identifiants enregistrés. Ils ont peut-être expiré ou été révoqués.", $detail),
            $status === 403 => new AdapterError('auth', "$service refuse l'accès : les droits accordés sont insuffisants.", $detail),
            $status === 404 => new AdapterError('not_found', "Élément introuvable sur $service.", $detail),
            $status === 409, $status === 412, $status === 422 && preg_match('/sha|conflict|fast.forward/i', $body) === 1 => new AdapterError('conflict', 'Le site a été modifié entre-temps. Réessayez.', $detail),
            $status === 429 => new AdapterError('rate_limited', "$service demande de patienter un peu. Réessayez dans une minute.", $detail),
            $status >= 500 => new AdapterError('remote', "$service rencontre un problème de son côté. Réessayez plus tard.", $detail),
            default => new AdapterError('invalid', "$service a refusé la modification.", $detail),
        };
    }
}
