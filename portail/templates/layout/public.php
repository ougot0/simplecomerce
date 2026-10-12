<?php /** @var string $content */ include __DIR__ . '/head.php'; ?>
<header class="pub-header">
    <a href="/" class="wordmark">Simple<span> </span>Commerce</a>
    <nav class="pub-nav" aria-label="Compte">
        <a href="/connexion">Se connecter</a>
        <?php if (!empty($signupOpen)): ?><a href="/inscription" class="btn btn-primary btn-small">Créer un compte</a><?php endif ?>
    </nav>
</header>
<main id="contenu"><?= $content ?></main>
<footer class="pub-footer">
    <span>Simple Commerce</span>
    <a href="/connexion">Se connecter</a>
</footer>
</body>
</html>
