<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters;

use SimpleCommerce\Config;

/**
 * Types de sites que l'on peut relier, avec leurs champs et la marche à suivre pour obtenir les accès.
 * Les libellés sont écrits pour des personnes non techniques.
 */
final class Catalog
{
    public const GROUPS = [
        'plateforme' => 'Mon site est fait avec une plateforme',
        'code' => 'Mon site a été codé sur mesure',
        'hebergeur' => 'Mon site est chez un hébergeur',
        'autre' => 'Autre',
    ];

    /** Hébergeurs courants : réglages habituels pour pré-remplir le formulaire (à vérifier dans l'espace client). */
    public const HOSTS = [
        'ovh' => ['name' => 'OVHcloud', 'sftp' => 'ssh.clusterXXX.hosting.ovh.net', 'ftp' => 'ftp.clusterXXX.hosting.ovh.net', 'port' => 22, 'root' => '/www',
            'where' => 'Espace client → Web Cloud → Hébergements → votre offre → onglet « FTP-SSH ». Le SFTP est inclus dans les offres Pro et Performance ; avec l\'offre Perso, choisissez FTP.'],
        'o2switch' => ['name' => 'o2switch', 'sftp' => 'votre-domaine.fr', 'ftp' => 'ftp.votre-domaine.fr', 'port' => 22, 'root' => '/public_html',
            'where' => 'cPanel → « Comptes FTP ». Pour le SFTP, autorisez d\'abord l\'adresse du portail dans « Autorisation SSH », ou utilisez FTP.'],
        'hostinger' => ['name' => 'Hostinger', 'sftp' => 'adresse IP indiquée dans hPanel', 'ftp' => 'ftp.votre-domaine.fr', 'port' => 65002, 'root' => '/domains/votre-domaine.fr/public_html',
            'where' => 'hPanel → Sites web → Gérer → « Accès SSH » (port 65002) ou « Comptes FTP ».'],
        'ionos' => ['name' => 'IONOS', 'sftp' => 'access-XXXXXX.webspace-host.com', 'ftp' => 'access-XXXXXX.webspace-host.com', 'port' => 22, 'root' => '/',
            'where' => 'Espace client → Hébergement → « SFTP & SSH ».'],
        'infomaniak' => ['name' => 'Infomaniak', 'sftp' => 'XXXX.ftp.infomaniak.com', 'ftp' => 'XXXX.ftp.infomaniak.com', 'port' => 22, 'root' => '/sites/votre-domaine.fr',
            'where' => 'Manager → Hébergement Web → « FTP / SSH ».'],
        'lws' => ['name' => 'LWS', 'sftp' => 'ftp.votre-domaine.fr', 'ftp' => 'ftp.votre-domaine.fr', 'port' => 22, 'root' => '/htdocs',
            'where' => 'Panel LWS → votre hébergement → « Gestion FTP ».'],
        'planethoster' => ['name' => 'PlanetHoster', 'sftp' => 'nom de votre serveur', 'ftp' => 'ftp.votre-domaine.fr', 'port' => 5022, 'root' => '/public_html',
            'where' => 'Espace client → votre compte → « Accès FTP / SSH ».'],
        'autre' => ['name' => 'Autre hébergeur ou serveur', 'sftp' => '', 'ftp' => '', 'port' => 22, 'root' => '/',
            'where' => 'Cherchez « FTP », « SFTP » ou « SSH » dans l\'espace client de votre hébergeur.'],
    ];

    /** @return array<int, array> */
    public static function all(): array
    {
        $contentDir = ['name' => 'contentDir', 'label' => 'Dossier du contenu', 'type' => 'text', 'default' => 'auto', 'advanced' => true, 'help' => '« auto » cherche tout seul (content, src/data, data…), ou le fichier content.json à la racine.'];
        $mediaDir = ['name' => 'mediaDir', 'label' => 'Dossier des photos', 'type' => 'text', 'default' => 'auto', 'advanced' => true, 'help' => 'Où ranger les photos envoyées. « auto » choisit public/images/simplecommerce, ou images/simplecommerce.'];
        $mediaPrefix = ['name' => 'mediaPublicPrefix', 'label' => 'Adresse des photos sur le site', 'type' => 'text', 'advanced' => true, 'placeholder' => '/images/simplecommerce'];
        $branch = ['name' => 'branch', 'label' => 'Branche', 'type' => 'text', 'default' => 'main', 'advanced' => true];
        $hostSelect = ['name' => 'hostPreset', 'label' => 'Votre hébergeur', 'type' => 'select', 'default' => 'ovh', 'options' => array_map(fn ($k, $h) => ['value' => $k, 'label' => $h['name']], array_keys(self::HOSTS), self::HOSTS),
            'help' => 'Sert seulement à pré-remplir le formulaire et à vous dire où trouver vos accès.'];

        $list = [
            ['id' => 'shopify', 'group' => 'plateforme', 'label' => 'Ma boutique Shopify', 'description' => 'Produits, prix, stocks, photos et collections.',
                'steps' => ['Dans Shopify : Paramètres → Applications et canaux de vente → Développer des applications.', 'Créez une application (nom : Simple Commerce), puis « Configurer les étendues de l\'API Admin ».', 'Cochez : write_products, read_products, write_inventory, read_inventory, read_locations.', 'Installez l\'application et copiez le « Jeton d\'accès à l\'API Admin » (il commence par shpat_). Il n\'est affiché qu\'une fois.'],
                'fields' => [
                    ['name' => 'shopDomain', 'label' => 'Adresse Shopify de la boutique', 'type' => 'text', 'required' => true, 'placeholder' => 'ma-boutique.myshopify.com'],
                    ['name' => 'accessToken', 'label' => "Jeton d'accès Admin API", 'type' => 'password', 'required' => true, 'secret' => true, 'placeholder' => 'shpat_…'],
                    ['name' => 'apiVersion', 'label' => "Version de l'API", 'type' => 'text', 'default' => '2025-07', 'advanced' => true],
                ]],
            ['id' => 'wordpress', 'group' => 'plateforme', 'label' => 'Mon site WordPress / WooCommerce', 'description' => 'Articles, produits WooCommerce et photos, chez n\'importe quel hébergeur.',
                'steps' => ['Dans WordPress : Comptes (Utilisateurs) → Profil.', 'Tout en bas, « Mots de passe d\'application » : donnez un nom (Simple Commerce) et cliquez sur « Ajouter ».', 'Copiez le mot de passe affiché (4 groupes de lettres). Ce n\'est pas votre mot de passe habituel.', 'Le site doit être en https.'],
                'fields' => [
                    ['name' => 'siteUrl', 'label' => 'Adresse du site', 'type' => 'url', 'required' => true, 'placeholder' => 'https://www.mon-site.fr'],
                    ['name' => 'username', 'label' => 'Identifiant WordPress', 'type' => 'text', 'required' => true],
                    ['name' => 'applicationPassword', 'label' => "Mot de passe d'application", 'type' => 'password', 'required' => true, 'secret' => true, 'placeholder' => 'abcd efgh ijkl mnop'],
                    ['name' => 'wooConsumerKey', 'label' => 'Clé client WooCommerce', 'type' => 'password', 'secret' => true, 'advanced' => true, 'placeholder' => 'ck_…', 'help' => 'Seulement si le mot de passe d\'application ne suffit pas pour WooCommerce.'],
                    ['name' => 'wooConsumerSecret', 'label' => 'Clé secrète WooCommerce', 'type' => 'password', 'secret' => true, 'advanced' => true, 'placeholder' => 'cs_…'],
                ]],
            ['id' => 'webflow', 'group' => 'plateforme', 'label' => 'Mon site Webflow', 'description' => 'Collections du CMS (produits, actualités, équipe…).',
                'steps' => ['Dans Webflow : paramètres du site → Apps & integrations → API access.', 'Générez un jeton avec les droits CMS et Assets (lecture et écriture).', 'Copiez le jeton.'],
                'fields' => [
                    ['name' => 'token', 'label' => "Jeton d'accès du site", 'type' => 'password', 'required' => true, 'secret' => true],
                    ['name' => 'siteId', 'label' => 'Identifiant du site', 'type' => 'text', 'advanced' => true, 'help' => 'Uniquement si le jeton donne accès à plusieurs sites.'],
                ]],
            ['id' => 'github', 'group' => 'code', 'label' => 'Mon site est sur GitHub', 'description' => 'Site fait sur mesure (Next.js, Astro, Hugo, Jekyll, Eleventy, HTML…) dont le code est sur GitHub. Il se met à jour tout seul après chaque modification (Vercel, Netlify, GitHub Pages, OVH…).',
                'steps' => ['Sur GitHub : Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token.', 'Repository access : « Only select repositories », choisissez le dépôt du site.', 'Permissions → Contents : « Read and write ».', 'Générez le jeton et copiez-le (il commence par github_pat_).'],
                'fields' => [
                    ['name' => 'repository', 'label' => 'Adresse du dépôt', 'type' => 'text', 'required' => true, 'placeholder' => 'https://github.com/mon-compte/mon-site'],
                    ['name' => 'token', 'label' => "Jeton d'accès", 'type' => 'password', 'required' => true, 'secret' => true, 'placeholder' => 'github_pat_…'],
                    $branch, $contentDir, $mediaDir, $mediaPrefix,
                ]],
            ['id' => 'gitlab', 'group' => 'code', 'label' => 'Mon site est sur GitLab', 'description' => 'Même principe que GitHub, pour gitlab.com ou un GitLab privé.',
                'steps' => ['Dans le projet GitLab : Settings → Access tokens → Add new token.', 'Rôle : Developer (ou plus). Portée : api.', 'Copiez le jeton (il commence par glpat-).'],
                'fields' => [
                    ['name' => 'repository', 'label' => 'Adresse du projet', 'type' => 'text', 'required' => true, 'placeholder' => 'https://gitlab.com/mon-groupe/mon-site'],
                    ['name' => 'token', 'label' => "Jeton d'accès", 'type' => 'password', 'required' => true, 'secret' => true, 'placeholder' => 'glpat-…'],
                    $branch, $contentDir, $mediaDir, $mediaPrefix,
                ]],
            ['id' => 'bitbucket', 'group' => 'code', 'label' => 'Mon site est sur Bitbucket', 'description' => 'Même principe que GitHub, pour Bitbucket Cloud.',
                'steps' => ['Dans le dépôt Bitbucket : Repository settings → Access tokens → Create.', 'Droits : Repositories → Read et Write.', 'Copiez le jeton. (Avec un mot de passe d\'application, renseignez aussi votre nom d\'utilisateur.)'],
                'fields' => [
                    ['name' => 'repository', 'label' => 'Adresse du dépôt', 'type' => 'text', 'required' => true, 'placeholder' => 'https://bitbucket.org/mon-espace/mon-site'],
                    ['name' => 'token', 'label' => "Jeton d'accès", 'type' => 'password', 'required' => true, 'secret' => true],
                    ['name' => 'username', 'label' => "Nom d'utilisateur", 'type' => 'text', 'advanced' => true, 'help' => "Seulement avec un mot de passe d'application."],
                    $branch, $contentDir, $mediaDir, $mediaPrefix,
                ]],
            ['id' => 'sftp', 'group' => 'hebergeur', 'label' => 'Mon site est chez un hébergeur (SFTP)', 'description' => 'OVHcloud, o2switch, Hostinger, IONOS, Infomaniak, LWS, PlanetHoster, serveur dédié… Connexion sécurisée, recommandée.',
                'steps' => ['Choisissez votre hébergeur ci-contre : le formulaire se pré-remplit et vous indique où trouver vos accès.', 'Notez le serveur, l\'identifiant et le mot de passe SFTP (ou SSH).', 'Le contenu du site doit être rangé dans un fichier comme content.json : voir « Préparer un site ».'],
                'fields' => [
                    $hostSelect,
                    ['name' => 'host', 'label' => 'Serveur', 'type' => 'text', 'required' => true, 'placeholder' => 'ssh.clusterXXX.hosting.ovh.net'],
                    ['name' => 'username', 'label' => 'Identifiant', 'type' => 'text', 'required' => true],
                    ['name' => 'password', 'label' => 'Mot de passe', 'type' => 'password', 'secret' => true],
                    ['name' => 'remoteRoot', 'label' => 'Dossier du site sur le serveur', 'type' => 'text', 'required' => true, 'default' => '/www'],
                    ['name' => 'port', 'label' => 'Port', 'type' => 'number', 'default' => '22', 'advanced' => true],
                    ['name' => 'privateKey', 'label' => 'Clé privée (à la place du mot de passe)', 'type' => 'textarea', 'secret' => true, 'advanced' => true, 'placeholder' => '-----BEGIN OPENSSH PRIVATE KEY-----'],
                    ['name' => 'passphrase', 'label' => 'Phrase de passe de la clé', 'type' => 'password', 'secret' => true, 'advanced' => true],
                    $contentDir, ['help' => 'Où ranger les photos envoyées, à partir du dossier du site. « auto » = images/simplecommerce.'] + $mediaDir, $mediaPrefix,
                    ['name' => 'hostFingerprint', 'label' => 'Empreinte du serveur', 'type' => 'text', 'advanced' => true, 'help' => 'Remplie automatiquement à la première connexion réussie.'],
                ]],
            ['id' => 'ftp', 'group' => 'hebergeur', 'label' => 'Mon site est chez un hébergeur (FTP)', 'description' => 'Si votre offre ne propose pas le SFTP (par exemple OVH Perso). La version chiffrée (FTPS) est utilisée par défaut.',
                'steps' => ['Choisissez votre hébergeur ci-contre pour savoir où trouver vos accès FTP.', 'Si la connexion sécurisée échoue, votre hébergeur ne la propose peut-être pas : le FTP non chiffré reste possible mais déconseillé.'],
                'fields' => [
                    $hostSelect,
                    ['name' => 'host', 'label' => 'Serveur', 'type' => 'text', 'required' => true, 'placeholder' => 'ftp.clusterXXX.hosting.ovh.net'],
                    ['name' => 'username', 'label' => 'Identifiant', 'type' => 'text', 'required' => true],
                    ['name' => 'password', 'label' => 'Mot de passe', 'type' => 'password', 'required' => true, 'secret' => true],
                    ['name' => 'remoteRoot', 'label' => 'Dossier du site sur le serveur', 'type' => 'text', 'required' => true, 'default' => '/www'],
                    ['name' => 'tls', 'label' => 'Sécurité', 'type' => 'select', 'default' => 'explicit', 'options' => [
                        ['value' => 'explicit', 'label' => 'FTPS (recommandé)'], ['value' => 'none', 'label' => 'FTP non chiffré (déconseillé)']]],
                    ['name' => 'port', 'label' => 'Port', 'type' => 'number', 'advanced' => true],
                    $contentDir, ['help' => 'Où ranger les photos envoyées, à partir du dossier du site. « auto » = images/simplecommerce.'] + $mediaDir, $mediaPrefix,
                ]],
            ['id' => 'custom-api', 'group' => 'autre', 'label' => 'Mon site a sa propre base de données', 'description' => 'Site sur mesure avec un serveur (PHP, Laravel, Symfony, Node, Django…). Votre développeur ajoute quelques adresses au site, décrites dans le guide « API sur mesure ».',
                'steps' => ['Votre développeur installe le point d\'accès Simple Commerce sur le site (exemple PHP fourni).', 'Il vous communique l\'adresse (ex. https://www.mon-site.fr/simplecommerce) et une clé secrète.'],
                'fields' => [
                    ['name' => 'baseUrl', 'label' => "Adresse du point d'accès", 'type' => 'url', 'required' => true, 'placeholder' => 'https://www.mon-site.fr/simplecommerce'],
                    ['name' => 'secret', 'label' => 'Clé secrète', 'type' => 'password', 'required' => true, 'secret' => true],
                ]],
        ];
        if (Config::demo()) {
            $list[] = ['id' => 'demo', 'group' => 'autre', 'label' => 'Site de démonstration', 'description' => 'Un des sites fictifs fournis avec la démonstration (dossier local).',
                'steps' => ['Choisissez « patisserie-lune » ou « atelier-brun ».'],
                'fields' => [['name' => 'folder', 'label' => 'Dossier', 'type' => 'select', 'required' => true, 'default' => 'patisserie-lune', 'options' => [['value' => 'patisserie-lune', 'label' => 'patisserie-lune'], ['value' => 'atelier-brun', 'label' => 'atelier-brun']]]]];
        }
        return $list;
    }

    public static function get(string $id): ?array
    {
        foreach (self::all() as $c) {
            if ($c['id'] === $id) {
                return $c;
            }
        }
        return null;
    }

    public static function label(string $id): string
    {
        return self::get($id)['label'] ?? $id;
    }

    public const NOT_YET = [
        ['Wix', "Accès possible via l'API Wix ; connecteur prévu."],
        ['Squarespace', "L'API produits demande l'offre Commerce Advanced ; connecteur prévu."],
        ['PrestaShop', 'Webservice PrestaShop ; connecteur prévu.'],
        ['Joomla, Drupal, Magento', 'Sur demande.'],
    ];

    /** Où se ferment les plateformes qui gèrent elles-mêmes la fermeture temporaire. */
    public const CLOSE_ELSEWHERE = [
        'shopify' => "Pour Shopify : dans l'administration de votre boutique, Boutique en ligne → Préférences → « Protection par mot de passe ». Cochez « Limiter l'accès aux visiteurs disposant du mot de passe » et écrivez votre message.",
        'wordpress' => 'Pour WordPress : installez une extension de maintenance (par exemple « LightStart » ou « Maintenance »), activez-la et écrivez votre message. Désactivez-la pour rouvrir.',
        'webflow' => 'Pour Webflow : dans les réglages du site, onglet « General », activez la protection par mot de passe. Désactivez-la pour rouvrir.',
    ];
}
