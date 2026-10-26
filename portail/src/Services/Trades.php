<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Adapters\Files\DbBackend;

/**
 * Métier du commerçant : il décide des choix et détails proposés d'un clic dans la fiche d'un produit
 * (tailles et couleurs pour la mode, parts et parfums pour un pâtissier, dimensions et matière pour un objet…).
 * Le commerçant peut le choisir dans Réglages → Boutique ; sinon il est deviné d'après ses produits.
 */
final class Trades
{
    /** Choix proposés : [nom, type un|plusieurs, nombre, options une par ligne]. Détails : débuts de ligne. */
    public const LIST = [
        'patisserie' => ['label' => 'Pâtisserie, boulangerie, chocolaterie', 'mots' => 'gâteau|gateau|tarte|cookie|biscuit|pâtisserie|patisserie|entremets|macaron|cupcake|brioche|pain|viennoiserie|chocolat|bûche|buche|fraisier|éclair|eclair|layer|cake',
            'choix' => [['Nombre de parts', 'un', 0, "4 parts\n6 parts\n8 parts"], ['Parfum', 'un', 0, "Vanille\nChocolat\nFraise"], ['Boîte à composer', 'plusieurs', 6, "Vanille\nChocolat\nPistache"], ['Message sur le gâteau', 'un', 0, "Sans message\nAvec message · +3 €"]],
            'details' => ['Allergènes : ', 'Conservation : ', 'Délai de commande : ', 'Poids : ']],
        'mode' => ['label' => 'Vêtements, chaussures, mode', 'mots' => 't-shirt|tee-shirt|tshirt|sweat|pull|robe|jupe|pantalon|jean|veste|manteau|chemise|chaussure|basket|sac|casquette|bonnet|écharpe|echarpe',
            'choix' => [['Taille', 'un', 0, "XS\nS\nM\nL\nXL"], ['Pointure', 'un', 0, "37\n38\n39\n40\n41\n42"], ['Couleur', 'un', 0, "Noir\nBlanc\nBeige"]],
            'details' => ['Matière : ', 'Coupe : ', 'Entretien : ', 'Le mannequin porte : ']],
        'bijoux' => ['label' => 'Bijoux, accessoires', 'mots' => 'bague|collier|bracelet|boucle|pendentif|bijou|montre|broche|chaîne|chaine',
            'choix' => [['Taille de bague', 'un', 0, "50\n52\n54\n56\n58"], ['Métal', 'un', 0, "Argent\nPlaqué or · +10 €\nOr · +80 €"], ['Longueur de chaîne', 'un', 0, "40 cm\n45 cm\n50 cm"], ['Gravure', 'un', 0, "Sans gravure\nAvec gravure · +15 €"]],
            'details' => ['Matière : ', 'Dimensions : ', 'Poids : ', 'Entretien : ']],
        'objets' => ['label' => 'Objets, décoration, impression 3D, artisanat', 'mots' => 'vase|lampe|bougie|figurine|statue|3d|impression|déco|deco|cadre|tableau|poterie|céramique|ceramique|mobilier|table|chaise|étagère|etagere|coussin|tapis',
            'choix' => [['Dimensions', 'un', 0, "Petit (10 cm)\nMoyen (20 cm) · 35 €\nGrand (30 cm) · 55 €"], ['Couleur', 'un', 0, "Blanc\nNoir\nTerracotta"], ['Matière', 'un', 0, "Résine\nBois\nMétal"], ['Finition', 'un', 0, "Brute\nPeinte · +5 €"]],
            'details' => ['Dimensions : ', 'Matière : ', 'Poids : ', 'Délai de fabrication : ']],
        'beaute' => ['label' => 'Cosmétiques, savons, parfums', 'mots' => 'savon|crème|creme|sérum|serum|parfum|huile|baume|shampoing|bougie parfumée|cosmétique|cosmetique|soin',
            'choix' => [['Contenance', 'un', 0, "50 ml\n100 ml · +8 €\n200 ml · +15 €"], ['Parfum', 'un', 0, "Sans parfum\nLavande\nFleur d'oranger"], ['Coffret à composer', 'plusieurs', 3, "Savon lavande\nSavon miel\nSavon argile"]],
            'details' => ['Ingrédients : ', 'Contenance : ', 'Utilisation : ', 'Type de peau : ']],
        'fleurs' => ['label' => 'Fleurs, plantes', 'mots' => 'bouquet|fleur|rose|plante|orchidée|orchidee|composition|pivoine|tulipe',
            'choix' => [['Taille du bouquet', 'un', 0, "Petit\nMoyen · 45 €\nGénéreux · 65 €"], ['Couleurs', 'un', 0, "Tons pastel\nTons vifs\nBlanc"], ['Vase', 'un', 0, "Sans vase\nAvec vase · +12 €"], ['Carte message', 'un', 0, "Sans carte\nAvec carte · +2 €"]],
            'details' => ['Hauteur : ', 'Entretien : ', 'Livraison : ']],
        'epicerie' => ['label' => 'Épicerie, traiteur, boissons', 'mots' => 'confiture|miel|thé|the|café|cafe|vin|bière|biere|jus|épice|epice|plat|traiteur|fromage|charcuterie|panier garni|huile d',
            'choix' => [['Format', 'un', 0, "250 g\n500 g · +6 €\n1 kg · +12 €"], ['Panier à composer', 'plusieurs', 4, "Confiture fraise\nMiel de fleurs\nThé vert"], ['Saveur', 'un', 0, "Nature\nÉpicée\nSucrée"]],
            'details' => ['Ingrédients : ', 'Allergènes : ', 'Poids : ', 'À consommer avant : ']],
        'autre' => ['label' => 'Autre commerce', 'mots' => '',
            'choix' => [['Taille', 'un', 0, "Petit\nMoyen\nGrand"], ['Couleur', 'un', 0, "Noir\nBlanc"], ['Option', 'un', 0, "Sans\nAvec · +5 €"], ['Lot à composer', 'plusieurs', 3, "Article 1\nArticle 2\nArticle 3"]],
            'details' => ['Dimensions : ', 'Matière : ', 'Poids : ']],
    ];

    /** Métier choisi par le commerçant, ou deviné d'après le nom du site et de ses produits. */
    public static function of(array $site): string
    {
        $chosen = (string) (Sites::extras($site)['metier'] ?? '');
        return isset(self::LIST[$chosen]) ? $chosen : self::guess($site);
    }

    /** Métier deviné d'après le nom du site et de ses produits (« autre » si rien ne ressort). */
    public static function guess(array $site): string
    {
        $words = (string) $site['name'];
        if ($site['connector'] === 'catalogue') {
            $content = json_decode((new DbBackend($site['id']))->read(DbBackend::CONTENT)['content'] ?? '{}', true) ?: [];
            foreach (array_slice((array) ($content['produits'] ?? []), 0, 100) as $p) {
                $words .= ' ' . (is_array($p) ? (string) ($p['nom'] ?? '') : '');
            }
        }
        $best = 'autre';
        $score = 0;
        foreach (self::LIST as $k => $t) {
            $n = $t['mots'] !== '' ? preg_match_all('/\b(' . $t['mots'] . ')/iu', $words) : 0;
            if ($n > $score) {
                [$best, $score] = [$k, $n];
            }
        }
        return $best;
    }

    /** Suggestions ajoutées aux champs « Choix proposés » et « Détails » de la fiche produit (catalogue hébergé). */
    public static function decorate(array $site, array $section): array
    {
        if ($site['connector'] !== 'catalogue' || $section['key'] !== 'produits') {
            return $section;
        }
        $t = self::LIST[self::of($site)];
        foreach ($section['fields'] as &$f) {
            if ($f['key'] === 'options' && $f['type'] === 'repeater') {
                $f['presets'] = array_map(fn ($c) => ['nom' => $c[0], 'type' => $c[1], 'nombre' => $c[2] ?: '', 'choix' => $c[3]], $t['choix']);
            } elseif ($f['key'] === 'details') {
                $f['presets'] = $t['details'];
            }
        }
        unset($f);
        return $section;
    }
}
