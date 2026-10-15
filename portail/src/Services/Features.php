<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Http\Halt;
use SimpleCommerce\Repo;

/**
 * Options du portail que l'administrateur allume ou éteint pour tous les clients (Administration → Options du portail).
 * Éteinte, une option disparaît des menus et ses pages répondent « introuvable » ; rien n'est effacé.
 */
final class Features
{
    public const LIST = [
        'rendezvous' => ['Prise de rendez-vous', 'Page « Rendez-vous » : prestations, créneaux, réservations depuis le site du client.'],
        'avis' => ['Avis clients', 'Page « Avis clients » : avis relus avant affichage sur le site du client.'],
        'statistiques' => ['Statistiques', 'Page « Statistiques » : visites du site, sans cookie.'],
        'anglais' => ['Version anglaise', 'Traduction du site du client en anglais en un clic.'],
        'ecrire' => ['« Écrire pour moi »', 'Boutons de rédaction automatique sous les descriptions.'],
        'telephone' => ['Aperçu sur téléphone', 'Bouton « Sur téléphone » en haut du menu de chaque site.'],
        'parrainage' => ['Parrainage', 'Lien de parrainage dans « Mon compte ».'],
        'aide' => ['« Besoin d\'aide ? »', 'Formulaire pour vous écrire, en bas du menu.'],
    ];

    private static ?array $cache = null;

    /** @return array<string, bool> */
    public static function all(): array
    {
        if (self::$cache === null) {
            $off = json_decode((string) Repo::meta('features_off', '[]'), true);
            $off = is_array($off) ? $off : [];
            self::$cache = [];
            foreach (array_keys(self::LIST) as $k) {
                self::$cache[$k] = !in_array($k, $off, true);
            }
        }
        return self::$cache;
    }

    public static function on(string $key): bool
    {
        return self::all()[$key] ?? true;
    }

    /** Page d'une option éteinte : « introuvable ». */
    public static function need(string $key): void
    {
        if (!self::on($key)) {
            throw Halt::notFound();
        }
    }

    /** @param string[] $off */
    public static function save(array $off): void
    {
        Repo::setMeta('features_off', json_encode(array_values(array_intersect($off, array_keys(self::LIST)))));
        self::$cache = null;
    }
}
