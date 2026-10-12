<?php /** @var bool $signupOpen */
$cta = $signupOpen ? ['/inscription', 'Créer mon compte'] : ['/connexion', 'Se connecter'];
?>
<section class="pub-hero">
    <div class="pub-wrap pub-hero-grid">
        <div>
            <h1>Modifiez votre site vous-même, sans développeur.</h1>
            <p class="pub-lead">Simple Commerce est l'espace où les commerçants et les artisans changent eux-mêmes les produits, les prix, les photos et les textes de leur site. Vous modifiez ici, votre site suit.</p>
            <div class="pub-actions">
                <a href="<?= e($cta[0]) ?>" class="btn btn-primary"><?= e($cta[1]) ?></a>
                <a href="#comment" class="btn btn-quiet">Comment ça marche</a>
            </div>
        </div>
        <div class="pub-card" aria-hidden="true">
            <div class="pub-card-row"><span class="pub-dot"></span><strong>Tarte au citron</strong><span class="pub-price">4,50 €</span></div>
            <div class="pub-card-row"><span class="pub-dot"></span><strong>Éclair au café</strong><span class="pub-price">3,80 €</span></div>
            <div class="pub-card-row pub-card-off"><span class="pub-dot"></span><strong>Fraisier</strong><span class="pub-tag">Masqué</span></div>
            <div class="pub-card-btn">Publier sur mon site</div>
        </div>
    </div>
</section>

<section class="pub-section" id="comment">
    <div class="pub-wrap">
        <h2>Comment ça marche</h2>
        <ol class="pub-steps">
            <li><span class="pub-num">1</span><h3>Vous reliez votre site</h3><p>Une seule fois, avec les accès que vous donne votre hébergeur. Simple Commerce retrouve tout seul vos produits, vos prix et les textes de vos pages.</p></li>
            <li><span class="pub-num">2</span><h3>Vous modifiez</h3><p>Un prix, une photo, un nouveau produit, un projet, vos horaires : tout se fait dans des formulaires simples, sans toucher au code.</p></li>
            <li><span class="pub-num">3</span><h3>Vous publiez</h3><p>Un clic sur « Publier sur mon site » et c'est en ligne. Une erreur ? Vous annulez en un clic.</p></li>
        </ol>
    </div>
</section>

<section class="pub-section pub-section-alt">
    <div class="pub-wrap pub-two">
        <div>
            <h2>Ce que vous pouvez faire</h2>
            <ul class="pub-list">
                <li><?= icon('store') ?> Ajouter, modifier ou supprimer des produits, des projets, des formules</li>
                <li><?= icon('photo') ?> Changer les photos, recadrées et allégées automatiquement</li>
                <li><?= icon('text') ?> Modifier les textes de chaque page de votre site</li>
                <li><?= icon('eye-off') ?> Masquer un produit épuisé sans l'effacer</li>
                <li><?= icon('lock') ?> Fermer votre boutique pendant les vacances, avec un message</li>
                <li><?= icon('calendar') ?> Programmer une publication à la date de votre choix</li>
                <li><?= icon('users') ?> Inviter un collègue, sur le site de votre choix seulement</li>
                <li><?= icon('undo') ?> Retrouver l'historique et revenir en arrière</li>
            </ul>
        </div>
        <div>
            <h2>Avec votre site, tel qu'il est</h2>
            <p>Votre site peut être chez n'importe quel hébergeur : OVHcloud, o2switch, Hostinger, IONOS, Infomaniak, LWS, PlanetHoster… Il fonctionne aussi avec Shopify, WordPress et WooCommerce, Webflow, GitHub, GitLab et Bitbucket.</p>
            <p>Votre site a été fait à la main ? Simple Commerce lit directement ses pages. Vous n'avez rien à préparer.</p>
            <p>Pas encore de boutique en ligne ? Gérez vos produits ici et affichez-les sur votre site en collant une seule ligne.</p>
        </div>
    </div>
</section>

<section class="pub-section">
    <div class="pub-wrap pub-two">
        <div>
            <h2>Vos accès restent protégés</h2>
            <p>Les accès à votre site sont chiffrés et ne sont jamais affichés, même à vous. Chaque client ne voit que ses propres sites. Les photos sont vérifiées avant d'être envoyées.</p>
        </div>
        <div>
            <h2>Tout reste sur votre site</h2>
            <p>Tout est enregistré sur votre propre site : si vous arrêtez Simple Commerce un jour, votre site continue de fonctionner exactement comme avant.</p>
        </div>
    </div>
</section>

<section class="pub-section pub-final">
    <div class="pub-wrap">
        <h2>Prêt à essayer ?</h2>
        <p>Une adresse e-mail et un mot de passe suffisent pour commencer.</p>
        <a href="<?= e($cta[0]) ?>" class="btn btn-primary"><?= e($cta[1]) ?></a>
    </div>
</section>
