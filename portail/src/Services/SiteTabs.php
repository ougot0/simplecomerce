<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Config;

/**
 * Pages ajoutées toutes seules au site du client, avec leur onglet dans le menu (au lieu d'une « ligne à coller »).
 * Pour ajouter une nouvelle page : une entrée dans KINDS, puis le bouton dans la page concernée (partials/site-tab.php).
 * Les rendez-vous ont leur propre code historique (BookingController::tab), sur le même principe.
 */
final class SiteTabs
{
    public const KINDS = [
        'avis' => ['file' => 'avis.html', 'label' => 'Avis', 'title' => 'Avis de nos clients', 'script' => '/avis/',
            'intro' => 'Lisez ce que nos clients pensent de nous, et donnez votre avis à votre tour.', 'back' => '/avis'],
        'horaires' => ['file' => 'horaires.html', 'label' => 'Horaires', 'title' => "Horaires d'ouverture", 'script' => '/horaires/',
            'intro' => 'Nos horaires de la semaine, toujours à jour.', 'back' => '/reglages#horaires'],
    ];

    /** Fichier de la page posée sur le site (null si absente). */
    public static function current(array $site, string $kind): ?string
    {
        $tabs = Sites::extras($site)['tabs'] ?? [];
        return is_array($tabs) && is_string($tabs[$kind] ?? null) ? $tabs[$kind] : null;
    }

    /** Le site peut-il recevoir une page automatiquement (site relié par fichiers) ? */
    public static function supported(array $site): bool
    {
        return Translation::supported($site);
    }

    /** Ajoute (ou retire) la page et son onglet. Renvoie le message à afficher ; lève RuntimeException si impossible. */
    public static function set(array $site, string $kind, bool $on): string
    {
        $k = self::KINDS[$kind] ?? null;
        if (!$k || !self::supported($site)) {
            throw new \RuntimeException('Pour ce type de site, collez la ligne ci-dessous dans votre site.');
        }
        $src = Config::url($k['script'] . Sites::publicKey($site) . '.js');
        $body = '<section data-sc-page="' . $kind . '" style="max-width:880px;margin:0 auto;padding:56px 20px 80px">'
            . "\n  <h1>" . htmlspecialchars($k['title'], ENT_QUOTES) . '</h1>'
            . "\n  <p>" . htmlspecialchars($k['intro'], ENT_QUOTES) . '</p>'
            . "\n  <script src=\"" . htmlspecialchars($src, ENT_QUOTES) . "\"></script>\n</section>";
        $summary = ($on ? 'Ajout' : 'Retrait') . ' de l\'onglet ' . $k['label'];
        try {
            $r = Sites::with($site, function (\SimpleCommerce\Adapters\Adapter $a) use ($on, $site, $body, $k, $kind, $summary) {
                if (!$a instanceof \SimpleCommerce\Adapters\Files\FileSiteAdapter) {
                    throw new \RuntimeException('Ce site ne peut pas recevoir de nouvel onglet automatiquement.');
                }
                return $a->setTabPage($kind, $k['file'], $k['label'], $k['title'] . ' — ' . $site['name'], $body, $on, $summary);
            }, null, true);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new \RuntimeException(\SimpleCommerce\Adapters\AdapterError::userMessageFor($e));
        }
        $tabs = Sites::extras($site)['tabs'] ?? [];
        $tabs = is_array($tabs) ? $tabs : [];
        if ($on) {
            $tabs[$kind] = $r['file'];
        } else {
            unset($tabs[$kind]);
        }
        Sites::setExtras($site, ['tabs' => $tabs]);
        return $on
            ? "C'est fait : l'onglet « {$k['label']} » est sur votre site, dans le menu de toutes les pages. Rechargez votre site pour le voir."
            : "L'onglet « {$k['label']} » et sa page ont été retirés de votre site.";
    }
}
