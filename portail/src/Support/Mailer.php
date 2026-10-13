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
        $from = (string) Config::get('mail_from', '');
        if ($from === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $headers = [
            'From' => 'Simple Commerce <' . $from . '>',
            'Reply-To' => $replyTo && filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : Config::contactEmail(),
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
}
