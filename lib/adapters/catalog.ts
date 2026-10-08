/**
 * Catalogue des types de sites — partagé entre le serveur et l'interface.
 * Les libellés sont écrits pour des personnes non techniques.
 */

export type ConnectorId =
  | "github"
  | "gitlab"
  | "bitbucket"
  | "sftp"
  | "ftp"
  | "shopify"
  | "wordpress"
  | "webflow"
  | "custom-api"
  | "demo";

export interface ConnectorField {
  name: string;
  label: string;
  type: "text" | "password" | "textarea" | "number" | "select" | "url";
  help?: string;
  placeholder?: string;
  required?: boolean;
  secret?: boolean;
  default?: string;
  options?: { value: string; label: string }[];
  pattern?: string;
  patternMessage?: string;
  /** Réglage avancé, replié par défaut. */
  advanced?: boolean;
}

export interface ConnectorDefinition {
  id: ConnectorId;
  /** Ce que le client reconnaît : « Ma boutique Shopify ». */
  label: string;
  description: string;
  group: "plateforme" | "code" | "hebergeur" | "autre";
  fields: ConnectorField[];
  /** Étapes pour obtenir les accès, affichées à côté du formulaire. */
  steps: string[];
  demoOnly?: boolean;
}

const contentDirField: ConnectorField = {
  name: "contentDir",
  label: "Dossier du contenu",
  type: "text",
  default: "auto",
  advanced: true,
  help: "« auto » cherche tout seul (content, src/data, data…). Sinon, indiquez le dossier.",
};
const mediaDirField: ConnectorField = {
  name: "mediaDir",
  label: "Dossier des photos",
  type: "text",
  default: "auto",
  advanced: true,
  help: "Où ranger les photos envoyées. « auto » choisit public/images/simplecommerce si possible.",
};
const mediaPrefixField: ConnectorField = {
  name: "mediaPublicPrefix",
  label: "Adresse des photos sur le site",
  type: "text",
  advanced: true,
  placeholder: "/images/simplecommerce",
  help: "Début de l'adresse sous laquelle le site affiche ces photos.",
};
const branchField: ConnectorField = { name: "branch", label: "Branche", type: "text", default: "main", advanced: true };

export const CONNECTORS: ConnectorDefinition[] = [
  {
    id: "shopify",
    label: "Ma boutique Shopify",
    description: "Produits, prix, stocks, photos et collections.",
    group: "plateforme",
    steps: [
      "Dans Shopify : Paramètres → Applications et canaux de vente → Développer des applications.",
      "Créez une application (nom : Simple Commerce), puis « Configurer les étendues de l'API Admin ».",
      "Cochez : write_products, read_products, write_inventory, read_inventory, read_locations.",
      "Installez l'application et copiez le « Jeton d'accès à l'API Admin » (il commence par shpat_). Il n'est affiché qu'une fois.",
    ],
    fields: [
      { name: "shopDomain", label: "Adresse Shopify de la boutique", type: "text", required: true, placeholder: "ma-boutique.myshopify.com" },
      { name: "accessToken", label: "Jeton d'accès Admin API", type: "password", required: true, secret: true, placeholder: "shpat_…" },
      { name: "apiVersion", label: "Version de l'API", type: "text", default: "2025-07", advanced: true },
    ],
  },
  {
    id: "wordpress",
    label: "Mon site WordPress / WooCommerce",
    description: "Articles, produits WooCommerce et photos.",
    group: "plateforme",
    steps: [
      "Dans WordPress : Comptes (Utilisateurs) → Profil.",
      "Tout en bas, section « Mots de passe d'application » : donnez un nom (Simple Commerce) et cliquez sur « Ajouter ».",
      "Copiez le mot de passe affiché (4 groupes de lettres). Ce n'est pas votre mot de passe habituel.",
      "Le site doit être en https.",
    ],
    fields: [
      { name: "siteUrl", label: "Adresse du site", type: "url", required: true, placeholder: "https://www.mon-site.fr" },
      { name: "username", label: "Identifiant WordPress", type: "text", required: true },
      { name: "applicationPassword", label: "Mot de passe d'application", type: "password", required: true, secret: true, placeholder: "abcd efgh ijkl mnop" },
      { name: "wooConsumerKey", label: "Clé client WooCommerce", type: "password", secret: true, advanced: true, placeholder: "ck_…", help: "Seulement si le mot de passe d'application ne suffit pas pour WooCommerce." },
      { name: "wooConsumerSecret", label: "Clé secrète WooCommerce", type: "password", secret: true, advanced: true, placeholder: "cs_…" },
    ],
  },
  {
    id: "webflow",
    label: "Mon site Webflow",
    description: "Collections du CMS (produits, actualités, équipe…).",
    group: "plateforme",
    steps: [
      "Dans Webflow : paramètres du site → Apps & integrations → API access.",
      "Générez un jeton avec les droits CMS (lecture et écriture) et Assets (lecture et écriture).",
      "Copiez le jeton.",
    ],
    fields: [
      { name: "token", label: "Jeton d'accès du site", type: "password", required: true, secret: true },
      { name: "siteId", label: "Identifiant du site", type: "text", advanced: true, help: "Uniquement si le jeton donne accès à plusieurs sites." },
    ],
  },
  {
    id: "github",
    label: "Mon site est sur GitHub",
    description: "Site fait sur mesure (Next.js, Astro, Hugo, Jekyll, Eleventy, HTML…) dont le code est sur GitHub. Le site se met à jour tout seul après chaque modification (Vercel, Netlify, GitHub Pages…).",
    group: "code",
    steps: [
      "Sur GitHub : Settings → Developer settings → Personal access tokens → Fine-grained tokens → Generate new token.",
      "Repository access : « Only select repositories » et choisissez le dépôt du site.",
      "Permissions → Repository permissions → Contents : « Read and write ».",
      "Générez le jeton et copiez-le (il commence par github_pat_).",
    ],
    fields: [
      { name: "repository", label: "Adresse du dépôt", type: "text", required: true, placeholder: "https://github.com/mon-compte/mon-site" },
      { name: "token", label: "Jeton d'accès", type: "password", required: true, secret: true, placeholder: "github_pat_…" },
      branchField,
      contentDirField,
      mediaDirField,
      mediaPrefixField,
      { name: "apiBase", label: "API GitHub Enterprise", type: "url", advanced: true, placeholder: "https://github.mon-entreprise.fr/api/v3" },
    ],
  },
  {
    id: "gitlab",
    label: "Mon site est sur GitLab",
    description: "Même principe que GitHub, pour gitlab.com ou un GitLab privé.",
    group: "code",
    steps: [
      "Dans le projet GitLab : Settings → Access tokens → Add new token.",
      "Rôle : Developer (ou plus). Portées : api.",
      "Copiez le jeton (il commence par glpat-).",
    ],
    fields: [
      { name: "repository", label: "Adresse du projet", type: "text", required: true, placeholder: "https://gitlab.com/mon-groupe/mon-site" },
      { name: "token", label: "Jeton d'accès", type: "password", required: true, secret: true, placeholder: "glpat-…" },
      branchField,
      contentDirField,
      mediaDirField,
      mediaPrefixField,
    ],
  },
  {
    id: "bitbucket",
    label: "Mon site est sur Bitbucket",
    description: "Même principe que GitHub, pour Bitbucket Cloud.",
    group: "code",
    steps: [
      "Dans le dépôt Bitbucket : Repository settings → Access tokens → Create.",
      "Droits : Repositories → Read et Write.",
      "Copiez le jeton. (Avec un mot de passe d'application, renseignez aussi votre nom d'utilisateur.)",
    ],
    fields: [
      { name: "repository", label: "Adresse du dépôt", type: "text", required: true, placeholder: "https://bitbucket.org/mon-espace/mon-site" },
      { name: "token", label: "Jeton d'accès", type: "password", required: true, secret: true },
      { name: "username", label: "Nom d'utilisateur", type: "text", advanced: true, help: "Seulement avec un mot de passe d'application." },
      branchField,
      contentDirField,
      mediaDirField,
      mediaPrefixField,
    ],
  },
  {
    id: "sftp",
    label: "Mon site est chez un hébergeur (SFTP)",
    description: "OVH, o2switch, Hostinger, Infomaniak, IONOS, serveur dédié… Accès sécurisé recommandé.",
    group: "hebergeur",
    steps: [
      "Chez votre hébergeur, ouvrez la rubrique FTP / SSH de l'hébergement (chez OVH : Hébergements → votre offre → FTP-SSH).",
      "Notez le serveur (ex. ssh.cluster0XX.hosting.ovh.net), l'identifiant et le mot de passe.",
      "Le dossier du site est souvent « /www » (OVH) ou « /public_html » (o2switch, Hostinger).",
      "Le contenu du site doit être dans un fichier comme content.json : voir le guide « Préparer mon site ».",
    ],
    fields: [
      { name: "host", label: "Serveur", type: "text", required: true, placeholder: "ssh.cluster0XX.hosting.ovh.net" },
      { name: "port", label: "Port", type: "number", default: "22", advanced: true },
      { name: "username", label: "Identifiant", type: "text", required: true },
      { name: "password", label: "Mot de passe", type: "password", secret: true },
      { name: "privateKey", label: "Clé privée (à la place du mot de passe)", type: "textarea", secret: true, advanced: true, placeholder: "-----BEGIN OPENSSH PRIVATE KEY-----" },
      { name: "passphrase", label: "Phrase de passe de la clé", type: "password", secret: true, advanced: true },
      { name: "remoteRoot", label: "Dossier du site sur le serveur", type: "text", required: true, default: "/www" },
      contentDirField,
      { ...mediaDirField, help: "Où ranger les photos envoyées, à partir du dossier du site. « auto » = images/simplecommerce." },
      mediaPrefixField,
      { name: "hostFingerprint", label: "Empreinte du serveur", type: "text", advanced: true, help: "Remplie automatiquement à la première connexion réussie." },
    ],
  },
  {
    id: "ftp",
    label: "Mon site est chez un hébergeur (FTP)",
    description: "Si votre hébergeur ne propose que le FTP. La version sécurisée (FTPS) est utilisée par défaut.",
    group: "hebergeur",
    steps: [
      "Chez votre hébergeur, rubrique FTP : notez le serveur, l'identifiant et le mot de passe.",
      "Si la connexion sécurisée échoue, votre hébergeur ne la propose peut-être pas : le FTP non chiffré reste possible mais déconseillé.",
    ],
    fields: [
      { name: "host", label: "Serveur", type: "text", required: true, placeholder: "ftp.cluster0XX.hosting.ovh.net" },
      { name: "username", label: "Identifiant", type: "text", required: true },
      { name: "password", label: "Mot de passe", type: "password", required: true, secret: true },
      {
        name: "tls",
        label: "Sécurité",
        type: "select",
        default: "explicit",
        options: [
          { value: "explicit", label: "FTPS (recommandé)" },
          { value: "implicit", label: "FTPS implicite (port 990)" },
          { value: "none", label: "FTP non chiffré (déconseillé)" },
        ],
      },
      { name: "port", label: "Port", type: "number", advanced: true },
      { name: "remoteRoot", label: "Dossier du site sur le serveur", type: "text", required: true, default: "/www" },
      contentDirField,
      { ...mediaDirField, help: "Où ranger les photos envoyées, à partir du dossier du site. « auto » = images/simplecommerce." },
      mediaPrefixField,
    ],
  },
  {
    id: "custom-api",
    label: "Mon site a sa propre base de données",
    description: "Site sur mesure avec un serveur (PHP, Laravel, Symfony, Node, Django…). Votre développeur ajoute quelques adresses au site, décrites dans le guide « API sur mesure ».",
    group: "autre",
    steps: [
      "Votre développeur installe le point d'accès Simple Commerce sur le site (exemple PHP fourni).",
      "Il vous communique l'adresse (ex. https://www.mon-site.fr/simplecommerce) et une clé secrète.",
    ],
    fields: [
      { name: "baseUrl", label: "Adresse du point d'accès", type: "url", required: true, placeholder: "https://www.mon-site.fr/simplecommerce" },
      { name: "secret", label: "Clé secrète", type: "password", required: true, secret: true },
    ],
  },
  {
    id: "demo",
    label: "Site de démonstration",
    description: "Dossier local, uniquement en mode démonstration.",
    group: "autre",
    demoOnly: true,
    steps: ["Choisissez un dossier dans demo/sites."],
    fields: [{ name: "folder", label: "Dossier", type: "text", required: true, default: "patisserie-lune" }],
  },
];

/** Plateformes demandées mais pas encore reliées, affichées honnêtement dans l'assistant. */
export const NOT_YET_SUPPORTED = [
  { name: "Wix", reason: "Accès possible via l'API Wix ; connecteur prévu." },
  { name: "Squarespace", reason: "L'API produits demande l'offre Commerce Advanced ; connecteur prévu." },
  { name: "PrestaShop", reason: "Webservice PrestaShop ; connecteur prévu." },
  { name: "Joomla, Drupal, Magento", reason: "Sur demande." },
];

export const GROUP_LABELS: Record<ConnectorDefinition["group"], string> = {
  plateforme: "Mon site est fait avec une plateforme",
  code: "Mon site a été codé sur mesure",
  hebergeur: "Mon site est chez un hébergeur",
  autre: "Autre",
};

export function connectorLabel(id: string): string {
  return CONNECTORS.find((c) => c.id === id)?.label ?? id;
}
