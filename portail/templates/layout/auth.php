<?php /** @var string $content */ include __DIR__ . '/head.php'; ?>
<div class="auth-page">
    <aside class="auth-side">
        <a href="/" class="wordmark">Simple<span> </span>Commerce</a>
        <div>
            <h1>Mettez à jour votre site vous-même.</h1>
            <p>Produits, prix, photos, horaires, actualités : vous modifiez ici, votre site suit.</p>
            <ul class="steps-list">
                <li><?= icon('draft') ?> Vous changez un texte, un prix ou une photo.</li>
                <li><?= icon('check') ?> Vous cliquez sur « Publier sur mon site ».</li>
                <li><?= icon('undo') ?> Une erreur ? Vous annulez en un clic.</li>
            </ul>
        </div>
        <p class="small">Votre site peut être chez n'importe quel hébergeur : OVHcloud, o2switch, Hostinger, IONOS, Shopify, WordPress, GitHub…</p>
    </aside>
    <main class="auth-main" id="contenu">
        <?php include dirname(__DIR__) . '/partials/flash.php'; ?>
        <?= $content ?>
    </main>
</div>
</body>
</html>
