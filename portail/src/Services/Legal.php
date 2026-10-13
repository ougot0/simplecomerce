<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Config;
use SimpleCommerce\Repo;

/**
 * Informations légales de l'éditeur de Simple Commerce (Administration → Informations légales),
 * reprises dans les mentions légales, les conditions générales et la politique de confidentialité.
 */
final class Legal
{
    public const FIELDS = [
        'nom' => ['Nom et prénom, ou nom de la société', 'Exemple : Jean Dupont, ou SAS Simple Commerce'],
        'statut' => ['Forme juridique', 'Exemple : Entrepreneur individuel (micro-entreprise), SAS au capital de 1 000 €…'],
        'adresse' => ['Adresse', 'Adresse postale complète du siège ou de l\'entrepreneur'],
        'siret' => ['Numéro SIRET', '14 chiffres, sur votre avis de situation INSEE (ou « en cours d\'immatriculation »)'],
        'tva' => ['Mention TVA', 'Micro-entreprise : « TVA non applicable, article 293 B du CGI ». Sinon : « N° TVA intracommunautaire FR… »'],
        'directeur' => ['Directeur de la publication', 'La personne responsable du site (souvent vous). Vide = même nom que ci-dessus.'],
        'telephone' => ['Téléphone (facultatif)', ''],
    ];
    public const UPDATED = '13 octobre 2026';

    public static function info(): array
    {
        $v = json_decode((string) Repo::meta('legal_info', '{}'), true) ?: [];
        $out = [];
        foreach (array_keys(self::FIELDS) as $k) {
            $out[$k] = trim((string) ($v[$k] ?? ''));
        }
        $out['directeur'] = $out['directeur'] ?: $out['nom'];
        $out['email'] = Config::contactEmail();
        return $out;
    }

    public static function save(array $values): void
    {
        $clean = [];
        foreach (array_keys(self::FIELDS) as $k) {
            $clean[$k] = mb_substr(trim((string) ($values[$k] ?? '')), 0, 300);
        }
        Repo::setMeta('legal_info', json_encode($clean, JSON_UNESCAPED_UNICODE));
    }

    public static function complete(): bool
    {
        $i = self::info();
        return $i['nom'] !== '' && $i['adresse'] !== '' && $i['siret'] !== '';
    }

    /** Valeur à afficher, ou un repère visible tant qu'elle n'est pas remplie. */
    public static function show(string $key): string
    {
        $v = self::info()[$key] ?? '';
        return $v !== '' ? $v : '[à compléter]';
    }
}
