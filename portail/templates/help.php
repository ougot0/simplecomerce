<div class="page-head"><div><h1>Aide</h1><p class="page-intro">Tout ce qu'il faut savoir pour mettre à jour votre site. Une question qui n'est pas ici ? Écrivez à la personne qui a créé votre site.</p></div></div>
<nav class="tabs" aria-label="Sommaire">
    <a href="#modifier">Modifier</a><a href="#photos">Photos</a><a href="#brouillons">Brouillons</a><a href="#annuler">Annuler</a><a href="#fermer">Fermer le site</a><a href="#equipe">Équipe</a><a href="#hebergeurs">Hébergeurs</a><a href="#preparer">Préparer un site</a>
</nav>
<div class="prose">
<section id="modifier">
    <h2>Modifier un produit, un prix, un texte</h2>
    <ol>
        <li>Dans le menu, choisissez la rubrique (par exemple « Nos gâteaux »).</li>
        <li>Cliquez sur l'élément à modifier, changez ce que vous voulez.</li>
        <li>Cliquez sur <strong>Publier sur mon site</strong>. C'est tout.</li>
    </ol>
    <p>Pour masquer un produit sans le supprimer (rupture, saison terminée), utilisez le bouton <strong>Masquer</strong> directement dans la liste. Il revient en un clic avec <strong>Afficher</strong>.</p>
    <p>Pour créer un produit qui ressemble à un autre, ouvrez-le et cliquez sur <strong>Dupliquer</strong> : la copie arrive masquée, vous la modifiez puis vous l'affichez.</p>
    <p>Selon la façon dont votre site est fait, il est à jour tout de suite (hébergeur, Shopify, WordPress) ou en une à deux minutes (site sur GitHub, le temps qu'il se reconstruise).</p>
</section>
<section id="photos">
    <h2>Photos</h2>
    <p>Cliquez sur <strong>Choisir une photo</strong>, recadrez-la, ajoutez une courte description, et validez. Les photos de téléphone sont acceptées : elles sont redressées, allégées et nettoyées (la position GPS est retirée) avant d'arriver sur votre site.</p>
    <p><strong>Mes photos</strong> retrouve les photos déjà envoyées, pour les réutiliser sans les renvoyer.</p>
    <p>Formats acceptés : JPEG, PNG, WebP. 8 Mo au plus.</p>
</section>
<section id="brouillons">
    <h2>Brouillons et publication programmée</h2>
    <p><strong>Enregistrer sans publier</strong> garde vos changements de côté : rien ne bouge sur votre site. Vous les retrouvez dans « Brouillons » pour les publier plus tard.</p>
    <p><strong>Programmer…</strong> publie automatiquement à la date et l'heure choisies : un menu de fête, une promotion qui commence lundi, l'annonce d'une fermeture. Si la publication échoue, vous êtes prévenu par e-mail et le brouillon reste en attente.</p>
</section>
<section id="annuler">
    <h2>Annuler une erreur</h2>
    <p>Dans <strong>Historique</strong>, chaque modification a un bouton <strong>Annuler</strong> : le site revient à l'état d'avant. L'historique montre aussi qui a changé quoi, avec l'ancienne et la nouvelle valeur.</p>
    <p><strong>Télécharger une sauvegarde</strong> (sur l'accueil de votre site) enregistre tout votre contenu dans un fichier, à garder chez vous.</p>
</section>
<section id="fermer">
    <h2>Fermer le site temporairement</h2>
    <p>Dans <strong>Réglages → Fermer le site temporairement</strong> : écrivez un message (« Nous sommes en congés jusqu'au 2 novembre ») et, si vous voulez, une date de réouverture. Vos visiteurs voient ce message à la place du site. Rien n'est effacé ; « Rouvrir le site » remet tout comme avant.</p>
    <p>Pour Shopify, WordPress et Webflow, la fermeture se fait dans leur propre administration : Simple Commerce vous indique exactement où cliquer.</p>
</section>
<section id="equipe">
    <h2>Travailler à plusieurs</h2>
    <p>Dans <strong>Réglages → Équipe</strong>, invitez un collègue par e-mail. Il crée son compte (ou se connecte) et retrouve le site. Choisissez « Modifier le contenu » pour un employé, « Tout gérer » pour un associé. Dans « Mon compte », chacun peut être prévenu par e-mail quand un autre modifie le site.</p>
</section>
<section id="hebergeurs">
    <h2>Mon site n'est pas chez OVH</h2>
    <p>Simple Commerce fonctionne avec <strong>tous les hébergeurs</strong> qui donnent un accès FTP ou SFTP : OVHcloud, o2switch, Hostinger, IONOS, Infomaniak, LWS, PlanetHoster, Gandi, serveurs dédiés… Le formulaire « Relier un site » se pré-remplit selon votre hébergeur et vous dit où trouver vos accès.</p>
    <p>Il fonctionne aussi avec les boutiques <strong>Shopify</strong>, les sites <strong>WordPress</strong> (et WooCommerce) et <strong>Webflow</strong>, et les sites dont le code est sur <strong>GitHub</strong>, <strong>GitLab</strong> ou <strong>Bitbucket</strong> (Next.js, Astro, Hugo, Jekyll, Eleventy… hébergés sur Vercel, Netlify, GitHub Pages ou ailleurs). Un site avec sa propre base de données peut être relié par une petite « API sur mesure » que son développeur ajoute.</p>
    <p>Vos accès sont chiffrés dès leur enregistrement, ne sont jamais réaffichés et ne quittent jamais le serveur du portail.</p>
</section>
<section id="preparer">
    <h2>Pour le créateur du site : préparer un site</h2>
    <p>Principe : ce que le client doit pouvoir modifier sort du code et va dans des fichiers de contenu que le site lit. Le portail modifie ces fichiers ; le site ne dépend jamais du portail.</p>
    <ul>
        <li>Site chez un hébergeur sans étape de construction (PHP, HTML) : un fichier <code>content.json</code> à la racine, lu par le site.</li>
        <li>Site construit (Next.js, Astro, Hugo…) : un dossier <code>content/</code> (JSON, YAML ou Markdown avec en-tête).</li>
        <li>Chaque élément d'une liste a un <code>id</code> stable. Prix en nombre, centimes ou texte : le format est conservé.</li>
        <li>Les nouvelles photos arrivent dans <code>images/simplecommerce/</code> (ou <code>public/images/simplecommerce/</code>).</li>
        <li>Fermeture : le portail écrit <code>simplecommerce-statut.json</code> = <code>{"ferme": true, "message": "…", "reouverture": "2026-11-02"}</code>. Le site affiche le message quand <code>ferme</code> vaut <code>true</code>.</li>
    </ul>
    <p>Exemple PHP, en haut de la page d'accueil d'un site chez un hébergeur :</p>
<pre class="code block">&lt;?php
$c = json_decode(file_get_contents(__DIR__ . '/content.json'), true);
$s = @json_decode(@file_get_contents(__DIR__ . '/simplecommerce-statut.json'), true);
if (!empty($s['ferme'])) { echo '&lt;h1&gt;Fermé temporairement&lt;/h1&gt;&lt;p&gt;' . htmlspecialchars($s['message']) . '&lt;/p&gt;'; exit; }
foreach ($c['produits'] as $p) { /* afficher $p['nom'], $p['prix'], $p['photo']… */ }</pre>
    <p>Le guide complet (schéma de contenu, API sur mesure) est dans le dossier <code>docs/</code> du projet.</p>
</section>
</div>
