<?php
declare(strict_types=1);

namespace SimpleCommerce\Support;

use SimpleCommerce\Config;

/**
 * E-mails (liens de connexion, invitations, avis de modification) via la fonction mail() de PHP,
 * disponible sur les hébergements mutualisés. En démonstration, rien n'est envoyé : on garde le message.
 */
final class Mailer
{
    /** Derniers messages « envoyés » en démonstration, pour les afficher à l'écran. */
    public static array $demoOutbox = [];

    public static function send(string $to, string $subject, string $text, ?string $replyTo = null): bool
    {
        if (Config::demo()) {
            self::$demoOutbox[] = compact('to', 'subject', 'text');
            Log::info('e-mail (démo)', ['to' => $to, 'subject' => $subject]);
            return true;
        }
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $reply = $replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : Config::contactEmail();
        // Service d'envoi Brevo réglé par l'administrateur : les e-mails arrivent bien mieux qu'avec mail() de l'hébergeur.
        $brevo = self::brevoKey();
        if ($brevo !== '') {
            if (self::sendBrevo($brevo, $to, $subject, $text, $reply)) {
                return true;
            }
            Log::warn('envoi par Brevo impossible, essai avec l\'hébergeur', ['to' => $to]);
        }
        $from = (string) Config::get('mail_from', '');
        if ($from === '') {
            return false;
        }
        $headers = [
            'From' => 'Simple Commerce <' . $from . '>',
            'Reply-To' => $reply,
            'MIME-Version' => '1.0',
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding' => '8bit',
        ];
        // Adresse de retour (-f) : améliore la délivrabilité chez les hébergeurs mutualisés comme OVH.
        $params = filter_var($from, FILTER_VALIDATE_EMAIL) && preg_match('/^[\w.+@-]+$/', $from) ? '-f' . $from : '';
        $ok = @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers, $params);
        if (!$ok) {
            Log::warn('envoi d\'e-mail impossible', ['to' => $to]);
        }
        return $ok;
    }

    // --- Brevo (réglages dans Administration → E-mails) ---

    private const AD = 'simplecommerce-mail';
    public static ?string $lastError = null;

    public static function settings(): array
    {
        $s = json_decode((string) \SimpleCommerce\Repo::meta('mail_settings', '{}'), true) ?: [];
        return $s + ['brevoKey' => '', 'senderEmail' => '', 'senderName' => 'Simple Commerce'];
    }

    public static function saveSettings(array $patch): void
    {
        if (array_key_exists('brevoKey', $patch)) {
            $patch['brevoKey'] = $patch['brevoKey'] === '' ? '' : Vault::seal(['v' => $patch['brevoKey']], self::AD);
        }
        \SimpleCommerce\Repo::setMeta('mail_settings', json_encode(array_replace(self::settings(), $patch)));
    }

    public static function brevoKey(): string
    {
        try {
            $sealed = (string) self::settings()['brevoKey'];
            return $sealed === '' ? '' : (string) (Vault::unseal($sealed, self::AD)['v'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    public static function sender(): string
    {
        return (string) (self::settings()['senderEmail'] ?: Config::get('mail_from', ''));
    }

    private static function sendBrevo(string $key, string $to, string $subject, string $text, string $reply): bool
    {
        self::$lastError = null;
        try {
            $res = Http::request('POST', (getenv('SC_BREVO_API') ?: 'https://api.brevo.com') . '/v3/smtp/email', [
                'api-key' => $key, 'Content-Type' => 'application/json', 'Accept' => 'application/json',
            ], json_encode([
                'sender' => ['name' => self::settings()['senderName'] ?: 'Simple Commerce', 'email' => self::sender()],
                'to' => [['email' => $to]],
                'replyTo' => ['email' => $reply],
                'subject' => $subject,
                'textContent' => $text,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 20);
        } catch (\Throwable $e) {
            self::$lastError = 'Brevo injoignable.';
            return false;
        }
        if ($res['status'] >= 200 && $res['status'] < 300) {
            return true;
        }
        $msg = (string) (json_decode($res['body'], true)['message'] ?? ('HTTP ' . $res['status']));
        self::$lastError = $msg;
        Log::warn('Brevo', ['status' => $res['status'], 'error' => $msg]);
        return false;
    }
}
