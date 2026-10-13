<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Log;
use SimpleCommerce\Support\Vault;

/**
 * Rédaction automatique avec Claude (« Écrire pour moi », traduction en anglais).
 * L'administrateur colle sa clé Claude dans Administration → Rédaction automatique ; elle est chiffrée
 * et ne sort jamais du serveur. Sans clé, les boutons n'apparaissent pas.
 */
final class Writer
{
    public const MODEL = 'claude-opus-5-5';
    private const AD = 'simplecommerce-ia';
    /** Demandes par personne et par jour (la clé est payée par le propriétaire du portail). */
    public const PER_DAY = 40;

    public static function settings(): array
    {
        $s = json_decode((string) Repo::meta('ai_settings', '{}'), true) ?: [];
        return $s + ['apiKey' => ''];
    }

    public static function saveKey(string $key): void
    {
        $s = self::settings();
        $s['apiKey'] = $key === '' ? '' : Vault::seal(['v' => $key], self::AD);
        Repo::setMeta('ai_settings', json_encode($s));
    }

    public static function key(): string
    {
        try {
            $sealed = (string) self::settings()['apiKey'];
            return $sealed === '' ? '' : (string) (Vault::unseal($sealed, self::AD)['v'] ?? '');
        } catch (\Throwable) {
            return '';
        }
    }

    public static function enabled(): bool
    {
        return self::key() !== '';
    }

    private static function client(): Client
    {
        return new Client(apiKey: self::key(), baseUrl: getenv('SC_ANTHROPIC_API') ?: null, requestOptions: ['timeout' => 90, 'maxRetries' => 1]);
    }

    /**
     * Une demande simple à Claude ; renvoie le texte de la réponse.
     * @throws \RuntimeException avec un message à montrer tel quel
     */
    public static function ask(string $system, string $prompt, int $maxTokens = 4000, string $effort = 'low'): string
    {
        if (!self::enabled()) {
            throw new \RuntimeException('La rédaction automatique n\'est pas activée sur ce portail.');
        }
        try {
            $message = self::client()->beta->messages->create(
                model: self::MODEL,
                maxTokens: $maxTokens,
                system: $system,
                messages: [['role' => 'user', 'content' => $prompt]],
                outputConfig: ['effort' => $effort],
                fallbacks: 'default',
                betas: ['server-side-fallback-2026-07-01'],
            );
        } catch (AuthenticationException | PermissionDeniedException $e) {
            Log::warn('Claude : clé refusée', ['err' => $e->getMessage()]);
            throw new \RuntimeException('La clé de rédaction automatique est refusée. L\'administrateur doit la vérifier.');
        } catch (RateLimitException $e) {
            throw new \RuntimeException('Le service de rédaction est très demandé. Réessayez dans une minute.');
        } catch (APIConnectionException $e) {
            throw new \RuntimeException('Le service de rédaction ne répond pas. Réessayez dans un instant.');
        } catch (\Throwable $e) {
            Log::warn('Claude : demande refusée', ['err' => $e->getMessage()]);
            throw new \RuntimeException('La rédaction n\'a pas abouti. Réessayez dans un instant.');
        }
        if ($message->stopReason === 'refusal') {
            throw new \RuntimeException('Le service de rédaction n\'a pas voulu écrire ce texte. Reformulez ou écrivez-le vous-même.');
        }
        $text = '';
        foreach ($message->content as $block) {
            if ($block->type === 'text') {
                $text .= $block->text;
            }
        }
        $text = trim($text);
        if ($text === '') {
            throw new \RuntimeException('La rédaction n\'a rien donné. Réessayez.');
        }
        return $text;
    }

    /**
     * « Écrire pour moi » : un texte de vente à partir des autres champs de la fiche.
     * @param array<string, string> $facts libellé → valeur (nom, prix, catégorie…)
     */
    public static function describe(string $siteName, string $fieldLabel, array $facts, string $current, string $mode, int $maxLength, bool $rich): string
    {
        $limit = $maxLength > 0 ? $maxLength : 600;
        $lines = [];
        foreach ($facts as $label => $value) {
            $lines[] = '- ' . $label . ' : ' . $value;
        }
        $task = match ($mode) {
            'raccourcir' => 'Raccourcis le texte actuel (environ deux fois plus court), sans perdre l\'essentiel.',
            'ameliorer' => 'Améliore le texte actuel : plus clair, plus agréable à lire, sans fautes. Garde les informations qu\'il donne.',
            default => $current !== '' ? 'Réécris le texte actuel pour le rendre plus engageant, en gardant ses informations.' : 'Écris ce texte à partir des informations de la fiche.',
        };
        $system = 'Tu rédiges les textes du site internet d\'un petit commerçant ou artisan français. Ton : simple, chaleureux, concret, '
            . 'en français correct, vouvoiement si tu t\'adresses au client. N\'invente aucune caractéristique précise (matière, taille, origine, '
            . 'allergènes, délai) qui ne figure pas dans les informations données. Pas d\'emoji, pas de titre, pas de guillemets autour du texte, '
            . 'pas de formule comme « Voici ». Réponds uniquement avec le texte final.';
        $prompt = "Site : $siteName\nChamp à remplir : $fieldLabel\n\nInformations de la fiche :\n" . ($lines ? implode("\n", $lines) : '- (aucune)')
            . ($current !== '' ? "\n\nTexte actuel :\n" . mb_substr($current, 0, 4000) : '')
            . "\n\n$task Longueur : $limit caractères au plus" . ($rich ? ', en un à trois paragraphes' : '') . '.';
        $text = self::ask($system, $prompt, 2000);
        $text = trim($text, " \n\"«»");
        if (mb_strlen($text) > $limit) {
            $cut = mb_substr($text, 0, $limit);
            $end = max(mb_strrpos($cut, '. ') ?: 0, mb_strrpos($cut, '.') === mb_strlen($cut) - 1 ? mb_strlen($cut) - 1 : 0);
            $text = $end > $limit / 2 ? mb_substr($cut, 0, $end + 1) : $cut;
        }
        if ($rich) {
            $paras = array_filter(array_map('trim', preg_split('/\n\s*\n/', $text)));
            $text = implode('', array_map(fn ($p) => '<p>' . nl2br(htmlspecialchars($p, ENT_QUOTES | ENT_HTML5, 'UTF-8'), false) . '</p>', $paras));
        }
        return $text;
    }
}
