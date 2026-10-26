<?php
declare(strict_types=1);

/**
 * Tests de Simple Commerce : php tests/run.php
 * Petit lanceur sans dépendance (l'hébergement mutualisé n'a pas PHPUnit).
 */

putenv('SC_DEMO=1');
require dirname(__DIR__) . '/src/bootstrap.php';

use SimpleCommerce\Adapters\ConflictError;
use SimpleCommerce\Adapters\Files\FileSiteAdapter;
use SimpleCommerce\Adapters\Files\LocalBackend;
use SimpleCommerce\Adapters\PendingAsset;
use SimpleCommerce\Content\ContentSchema;
use SimpleCommerce\Content\FormInput;
use SimpleCommerce\Content\Formats;
use SimpleCommerce\Content\Price;
use SimpleCommerce\Content\Values;
use SimpleCommerce\Services\Changes;
use SimpleCommerce\Services\Media;
use SimpleCommerce\Support\Html;
use SimpleCommerce\Support\Log;
use SimpleCommerce\Support\Net;
use SimpleCommerce\Support\Text;
use SimpleCommerce\Support\Vault;

$passed = 0;
$failed = [];
function check(bool $ok, string $what): void
{
    global $passed, $failed;
    $ok ? $passed++ : $failed[] = $what;
}
function throws(callable $fn, string $class = \Throwable::class): bool
{
    try {
        $fn();
    } catch (\Throwable $e) {
        return $e instanceof $class;
    }
    return false;
}
function test(string $name, callable $fn): void
{
    global $failed;
    try {
        $fn();
    } catch (\Throwable $e) {
        $failed[] = "$name : exception " . get_class($e) . ' — ' . $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')';
    }
}
function tmpSite(string $name): string
{
    $dir = sys_get_temp_dir() . '/sc-test-' . bin2hex(random_bytes(4));
    exec('cp -r ' . escapeshellarg(SC_ROOT . "/demo/sites/$name") . ' ' . escapeshellarg($dir));
    return $dir;
}
function adapter(string $dir, bool $webRoot): array
{
    $a = new FileSiteAdapter(new LocalBackend($dir), ['contentDir' => 'auto', 'media' => ['dir' => 'auto', 'publicPrefix' => '/images/simplecommerce'], 'publicUrl' => '', 'webRoot' => $webRoot]);
    $schema = ContentSchema::parse($a->discover()['schema']);
    return [$a, $schema];
}
$ctx = fn (array $assets = []) => ['author' => 'Test', 'summary' => 'test', 'changeId' => 'c1', 'assets' => $assets];

// ---------------------------------------------------------------- sécurité
test('texte enrichi', function () {
    $out = Html::sanitize('<p onclick="x()">Bon<b>jour</b> <script>alert(1)</script><a href="javascript:alert(1)">lien</a> <a href="https://ex.fr">ok</a><img src=x onerror=y></p>');
    check(!str_contains($out, 'script') && !str_contains($out, 'onclick') && !str_contains($out, 'javascript') && !str_contains($out, '<img'), 'Html::sanitize retire scripts, événements et liens dangereux');
    check(str_contains($out, '<strong>jour</strong>') && str_contains($out, 'href="https://ex.fr"'), 'Html::sanitize garde gras et liens sûrs');
});
test('adresses privées', function () {
    foreach (['127.0.0.1', '10.0.0.4', '192.168.1.1', '169.254.169.254', '::1', 'fd00::1', '172.16.0.1', '0.0.0.0'] as $ip) {
        check(Net::isPrivateIp($ip), "adresse privée reconnue : $ip");
    }
    check(!Net::isPrivateIp('51.91.236.255'), 'adresse publique acceptée');
    putenv('SC_ALLOW_PRIVATE');
    check(throws(fn () => Net::assertPublicUrl('https://localhost/x')), 'localhost refusé');
    check(throws(fn () => Net::assertPublicUrl('http://exemple.fr/')), 'http refusé');
    check(throws(fn () => Net::assertPublicUrl('https://user:pw@exemple.fr/')), 'identifiants dans l\'URL refusés');
});
test('coffre', function () {
    $sealed = Vault::seal(['token' => 'ghp_secret123'], 'site-a');
    check(!str_contains($sealed, 'ghp_secret123'), 'secret chiffré');
    check(Vault::unseal($sealed, 'site-a') === ['token' => 'ghp_secret123'], 'secret relu');
    check(throws(fn () => Vault::unseal($sealed, 'site-b')), 'secret recopié sur un autre site refusé');
    $raw = base64_decode(substr($sealed, 3));
    $raw[30] = chr(ord($raw[30]) ^ 1);
    check(throws(fn () => Vault::unseal('v1:' . base64_encode($raw), 'site-a')), 'altération détectée');
    check(Vault::fingerprint('shpat_abcdef123456') === '…3456', 'empreinte : 4 caractères seulement');
});
test('journal', function () {
    $r = Log::redact(['password' => 'x', 'nested' => ['accessToken' => 'y', 'ok' => 'visible'], 'msg' => 'jeton ghp_ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789 utilisé']);
    check($r['password'] !== 'x' && $r['nested']['accessToken'] !== 'y' && $r['nested']['ok'] === 'visible', 'journal : secrets masqués par nom');
    check(!str_contains(json_encode($r), 'ghp_ABCDEF'), 'journal : jetons masqués par forme');
});
test('photos', function () {
    $img = imagecreatetruecolor(3000, 2000);
    imagefill($img, 0, 0, imagecolorallocate($img, 200, 150, 100));
    ob_start();
    imagejpeg($img, null, 90);
    $jpeg = (string) ob_get_clean();
    $p = Media::process($jpeg, 'Ma Photo.JPG', 1600);
    check($p['width'] === 1600 && str_starts_with($p['bytes'], 'RIFF') && str_ends_with($p['fileName'], '.webp'), 'photo réencodée en WebP et redimensionnée');
    check(throws(fn () => Media::process('<?php echo 1; ?>', 'photo.jpg'), \InvalidArgumentException::class), 'fichier non image refusé même renommé');
    check(throws(fn () => Media::process('<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'a.png'), \InvalidArgumentException::class), 'SVG refusé');
    check(throws(fn () => Media::process(str_repeat('a', Media::MAX_BYTES + 1), 'a.jpg'), \InvalidArgumentException::class), 'fichier trop lourd refusé');
});

// ---------------------------------------------------------------- textes et valeurs
test('français', function () {
    check(Text::plural(2, 'gâteau') === '2 gâteaux' && Text::plural(1, 'gâteau') === '1 gâteau' && Text::plural(3, 'prix') === '3 prix', 'pluriels');
    check(Text::addLabel('actualité') === 'Ajouter une actualité' && Text::addLabel('gâteau') === 'Ajouter un gâteau', 'articles');
    check(Text::price(4.5) === "4,50\u{202f}€" || Text::price(4.5) === '4,50 €', 'prix affiché');
    check(Text::parseDecimal('1 234,50 €') === 1234.5 && Text::parseDecimal('4.5') === 4.5, 'lecture des nombres');
});
test('prix', function () {
    check(Price::toStored(['priceFormat' => ['store' => 'string', 'decimal' => ',', 'suffix' => ' €']], 4.9) === '4,90 €', 'prix texte');
    check(Price::toStored(['priceFormat' => ['store' => 'cents']], 4.9) === 490, 'prix en centimes');
    check(Price::toNumber(['priceFormat' => ['store' => 'string']], '4,50 €') === 4.5, 'prix relu');
});
test('formulaire', function () {
    $fields = [
        ['key' => 'nom', 'label' => 'Nom', 'type' => 'text', 'required' => true],
        ['key' => 'prix', 'label' => 'Prix', 'type' => 'price', 'priceFormat' => ['store' => 'number']],
        ['key' => 'dispo', 'label' => 'Dispo', 'type' => 'boolean'],
        ['key' => 'tags', 'label' => 'Tags', 'type' => 'list'],
        ['key' => 'secret', 'label' => 'Ref', 'type' => 'text', 'hidden' => true],
        ['key' => 'horaires', 'label' => 'Horaires', 'type' => 'repeater', 'fields' => [['key' => 'jour', 'label' => 'Jour', 'type' => 'text']]],
    ];
    $raw = ['nom' => 'Tarte', 'prix' => '4,90', 'dispo' => '0', 'tags' => ['', 'été', ' '], 'secret' => 'pirate', 'horaires' => ['_' => '', 'n1' => ['jour' => 'Mardi', '__index' => '1'], 'n2' => ['jour' => 'Mercredi']]];
    $current = ['nom' => 'Ancien', 'secret' => 'REF-1', 'inconnu' => 42, 'horaires' => [['jour' => 'Lundi', 'cache' => 'a'], ['jour' => 'Mardi', 'cache' => 'b']]];
    [$data, $errors] = Values::normalize($fields, FormInput::coerce($fields, $raw), $current);
    check(!$errors, 'formulaire valide');
    check($data['prix'] === 4.9 && $data['dispo'] === false && $data['tags'] === ['été'], 'valeurs converties (prix, oui/non, liste)');
    check($data['secret'] === 'REF-1' && $data['inconnu'] === 42, 'champ masqué et champ inconnu conservés');
    check($data['horaires'] === [['jour' => 'Mardi', 'cache' => 'b'], ['jour' => 'Mercredi']], 'lignes répétées : données cachées gardées, ordre respecté');
    [, $e2] = Values::normalize($fields, FormInput::coerce($fields, ['nom' => '', 'prix' => 'abc']), []);
    check(isset($e2['nom'], $e2['prix']) && str_contains($e2['prix'], 'Prix invalide'), 'erreurs en français');
    check(FormInput::fingerprint(['fields' => $fields], ['nom' => 'a', 'prix' => 4.0]) === FormInput::fingerprint(['fields' => $fields], ['prix' => 4, 'nom' => 'a']), 'empreinte stable');
});
test('formats', function () {
    $json = "{\n    \"a\": 1,\n    \"vide\": {},\n    \"liste\": []\n}\n";
    $p = Formats::parse($json, 'json');
    check(Formats::serialize($p, $p['data']) === $json, 'JSON réécrit à l\'identique (indentation, objet vide)');
    $md = "---\ntitre: Maison\ndate: 2024-05-01\n---\nTexte **gras**.\n";
    $p = Formats::parse($md, 'markdown');
    check($p['data']['titre'] === 'Maison' && trim($p['body']) === 'Texte **gras**.', 'Markdown lu');
    check(str_contains(Formats::serialize($p, $p['data'], $p['body']), 'date: 2024-05-01'), 'date YAML conservée sans guillemets');
});
test('fichier JavaScript', function () {
    $js = "/* Catalogue */\nglobalThis.BOUTIQUE = {\n  \"produits\": [\n    { \"slug\": \"a\", \"price\": \"49 €\" }\n  ]\n};\n";
    $p = Formats::parse($js, 'js');
    check($p['data']['produits'][0]['price'] === '49 €', 'données lues dans un fichier JavaScript');
    check(Formats::serialize($p, $p['data']) === "/* Catalogue */\nglobalThis.BOUTIQUE = {\n  \"produits\": [\n    {\n      \"slug\": \"a\",\n      \"price\": \"49 €\"\n    }\n  ]\n};\n", 'commentaire et déclaration conservés');
    check(throws(fn () => Formats::parse('function x() { return 1; }', 'js')), 'fichier de code ordinaire ignoré');
    $f = ['priceFormat' => Price::detect(['49 €', '89 €'])];
    check(Price::toStored($f, 79.0) === '79 €' && Price::toStored($f, 79.5) === '79,50 €', 'prix ronds gardés ronds');
    check(Price::toStored(['priceFormat' => Price::detect(['4,50 €', '5,00 €'])], 5.0) === '5,00 €', 'centimes gardés quand le site les écrit');
});

// ---------------------------------------------------------------- moteur de contenu (site chez un hébergeur)
test('site hébergeur', function () use ($ctx) {
    $dir = tmpSite('patisserie-lune');
    [$a, $schema] = adapter($dir, true);
    $g = ContentSchema::find($schema, 'gateaux');
    check($g && $g['kind'] === 'collection' && ContentSchema::find($schema, 'infos')['kind'] === 'singleton', 'rubriques détectées dans content.json');
    $before = file_get_contents("$dir/content.json");
    $e = $a->getEntry($g, '1');
    $r = $a->updateEntry($g, '1', ['prix' => '4,90 €'] + $e['data'], $e['data'], $ctx());
    $after = file_get_contents("$dir/content.json");
    check(str_contains($after, '"prix": "4,90 €"') && substr_count($before, "\n") === substr_count($after, "\n"), 'modification d\'un élément sans toucher au reste');
    check(throws(fn () => $a->updateEntry($g, '1', $e['data'], $e['data'], $ctx()), ConflictError::class), 'version modifiée entre-temps refusée');
    $c = $a->createEntry($g, ['nom' => 'Chou', 'prix' => '2 €', 'disponible' => true], $ctx());
    check($c['id'] === '7' || $c['id'] === 7 || is_numeric($c['id']), 'nouvel identifiant du même type');
    $a->deleteEntry($g, (string) $c['id'], null, $ctx());
    check(count($a->listEntries($g)) === 5, 'suppression');
    $asset = new PendingAsset('11111111-1111-1111-1111-111111111111', 'RIFFxxxxWEBP', 'chou.webp', 'image/webp');
    $r = $a->updateEntry($g, '2', ['photo' => Values::MEDIA_PREFIX . $asset->token] + $a->getEntry($g, '2')['data'], null, $ctx([$asset]));
    check($r['after']['photo'] === '/images/simplecommerce/chou.webp' && is_file("$dir/images/simplecommerce/chou.webp"), 'photo écrite avec le contenu');
    $a->setStatus(['closed' => true, 'message' => 'Congés', 'reopenOn' => '2026-11-02'], $ctx());
    check($a->getStatus()['closed'] === true && $a->getStatus()['message'] === 'Congés', 'fermeture temporaire écrite et relue');
    check(throws(fn () => (new LocalBackend($dir))->read('../../etc/passwd')), 'chemin qui sort du site refusé');
});
test('site Markdown', function () use ($ctx) {
    $dir = tmpSite('atelier-brun');
    [$a, $schema] = adapter($dir, false);
    $p = ContentSchema::find($schema, 'projets');
    check($p && count($a->listEntries($p)) >= 2, 'dossier Markdown détecté');
    $first = $a->listEntries($p)[0];
    $a->updateEntry($p, $first['id'], ['titre' => 'Nouveau titre'] + $first['data'], $first['data'], $ctx());
    check($a->getEntry($p, $first['id'])['data']['titre'] === 'Nouveau titre', 'élément Markdown modifié');
});
test('annulation', function () {
    $section = ['key' => 'p', 'kind' => 'collection', 'fields' => []];
    $base = ['status' => 'applied', 'reverted_by_id' => null, 'entry_id' => 'x', 'before_order' => null, 'after_order' => null];
    check(Changes::inverse($base + ['before_json' => ['a' => 1], 'after_json' => ['a' => 2]], $section)['type'] === 'update', 'annuler une modification');
    check(Changes::inverse($base + ['before_json' => null, 'after_json' => ['a' => 2]], $section)['type'] === 'delete', 'annuler un ajout');
    check(Changes::inverse($base + ['before_json' => ['a' => 1], 'after_json' => null], $section)['type'] === 'create', 'annuler une suppression');
    $r = Changes::inverse(['before_order' => ['n0', 'n1', 'n2'], 'after_order' => ['n2', 'n0', 'n1'], 'before_json' => null, 'after_json' => null] + $base, $section);
    check($r['ids'] === ['n1', 'n2', 'n0'], 'annuler un nouvel ordre sans identifiants');
    check(Changes::inverse(['reverted_by_id' => 'y'] + $base + ['before_json' => ['a' => 1], 'after_json' => ['a' => 2]], $section) === null, 'pas de double annulation');
});

test('boutique', function () {
    $secret = 'whsec_test123456789';
    $body = '{"type":"x"}';
    $t = (string) time();
    check(\SimpleCommerce\Services\Billing::checkSignature($body, "t=$t,v1=" . hash_hmac('sha256', "$t.$body", $secret), $secret), 'avis Stripe authentique accepté');
    check(!\SimpleCommerce\Services\Billing::checkSignature($body, "t=$t,v1=" . hash_hmac('sha256', "$t.$body", 'autre'), $secret), 'avis Stripe mal signé refusé');
    $old = (string) (time() - 3600);
    check(!\SimpleCommerce\Services\Billing::checkSignature($body, "t=$old,v1=" . hash_hmac('sha256', "$old.$body", $secret), $secret), 'avis Stripe trop ancien refusé');
    check(\SimpleCommerce\Services\Stats::source('google.fr') === 'Google' && \SimpleCommerce\Services\Stats::source('instagram.com') === 'Instagram', 'provenance lisible');
    $dir = tmpSite('patisserie-lune');
    file_put_contents("$dir/index.html", "<html><body><h1>Bonjour</h1>\n</body></html>\n");
    $before = file_get_contents("$dir/index.html");
    $a = new FileSiteAdapter(new LocalBackend($dir), ['contentDir' => '', 'media' => ['dir' => 'auto', 'publicPrefix' => '/images'], 'publicUrl' => 'https://x', 'webRoot' => true]);
    $src = 'https://portail.test/p/0123456789abcdef01234567.js';
    check($a->setPageScript($src, true, 't')['changed'] === 1 && str_contains(file_get_contents("$dir/index.html"), $src), 'compteur ajouté à la page');
    check($a->setPageScript($src, true, 't')['changed'] === 0, 'compteur pas ajouté deux fois');
    $a->setPageScript($src, false, 't');
    check(file_get_contents("$dir/index.html") === $before, 'compteur retiré, page identique');
});

test('sécurité (audit du 13/10)', function () {
    foreach (['0:0:0:0:0:ffff:127.0.0.1', '::0:ffff:7f00:1', '::ffff:169.254.169.254', '64:ff9b::10.0.0.1', '2002:7f00:1::', 'fec0::1'] as $ip) {
        check(Net::isPrivateIp($ip), "adresse interne déguisée refusée : $ip");
    }
    check(!Net::isPrivateIp('8.8.8.8') && !Net::isPrivateIp('2606:4700::1111'), 'adresses publiques acceptées');
    $img = ['key' => 'photo', 'label' => 'Photo', 'type' => 'image'];
    [, $e] = Values::normalize([$img], ['photo' => '/x.jpg" onerror="alert(1)'], []);
    check(!empty($e), 'photo piégée (guillemet) refusée');
    [, $e] = Values::normalize([$img], ['photo' => "/a.jpg');background:url(https://evil"], []);
    check(!empty($e), 'photo piégée (style) refusée');
    [$d, $e] = Values::normalize([$img], ['photo' => '/images/mon gateau (1).jpg'], ['photo' => '/images/mon gateau (1).jpg']);
    check(!$e, 'photo existante inchangée gardée');
    $txt = ['key' => 'nom', 'label' => 'Nom', 'type' => 'text'];
    [, $e] = Values::normalize([$txt], ['nom' => 'Tarte <img src=x onerror=alert(1)>'], []);
    check(!empty($e), 'balise HTML dans un texte refusée');
    [, $e] = Values::normalize([$txt], ['nom' => 'Prix < 5 € et 3 > 2'], []);
    check(!$e, 'signes < et > ordinaires acceptés');
    check(throws(fn () => \SimpleCommerce\Content\HtmlCards::copyPage('<?php echo \'<h1>A</h1>\'; ?>', 'A', 'B', null)), 'page PHP jamais recopiée');
});

test('Version anglaise', function () {
    $T = \SimpleCommerce\Content\HtmlTranslate::class;
    $html = "<!doctype html><html lang=\"fr\"><head><meta charset=\"utf-8\"><title>Pâtisserie</title><meta name=\"description\" content=\"Gâteaux maison\"></head>"
        . "<body><h1>Nos gâteaux</h1><p>Tarte <strong>au citron</strong></p><img src=\"a.jpg\" alt=\"Une tarte\"><a href=\"contact.html#x\">Contact</a>"
        . "<script>var x = 'Bonjour';</script></body></html>";
    $segs = $T::segments($html);
    check(in_array('Nos gâteaux', $segs, true) && in_array('Une tarte', $segs, true) && in_array('Gâteaux maison', $segs, true), 'textes, alt et description relevés');
    check(!in_array("var x = 'Bonjour';", $segs, true), 'scripts jamais traduits');
    $out = $T::render($html, ['Nos gâteaux' => 'Our cakes', 'Une tarte' => 'A tart'], fn ($h) => $h === 'contact.html#x' ? 'contact-en.html#x' : null, 'index.html');
    check(str_contains($out, 'Our cakes') && str_contains($out, 'alt="A tart"') && str_contains($out, 'lang="en"'), 'page anglaise fabriquée');
    check(str_contains($out, 'href="contact-en.html#x"') && str_contains($out, 'hreflang="fr"') && $T::isOurs($out), 'liens et bouton « Français »');
    check(!str_contains($out, '<?xml') && str_contains($out, 'Pâtisserie'), 'accents écrits en clair');
    $fr = $T::markFrench($html, 'index-en.html');
    check(str_contains($fr, 'English') && $T::markFrench($fr, null) === $html, 'bouton « English » ajouté puis retiré sans trace');
    check($T::enPath('dossier/projet.html') === 'dossier/projet-en.html', 'nom des pages anglaises');
});

test('Confirmation de l\'adresse ouverte dans un autre navigateur', function () {
    $R = \SimpleCommerce\Repo::class;
    $A = \SimpleCommerce\Services\Auth::class;
    $u = $R::createUser('v-' . bin2hex(random_bytes(4)) . '@test.invalid', 'Val', 'mot-de-passe-9');
    $A::markUnverified($u['id']);
    $R::createToken($u['id'], 'remember', 3600);
    $hash = $R::user($u['id'])['password_hash'];
    $r = $A::consumeVerification($R::createToken($u['id'], 'verify', 3600));
    check($A::isVerified($R::user($u['id'])), 'adresse confirmée');
    check($R::user($u['id'])['password_hash'] === $hash, 'mot de passe gardé (l\'application reste connectée)');
    check((int) \SimpleCommerce\Db::value("SELECT COUNT(*) FROM sc_tokens WHERE user_id = ? AND kind = 'remember'", [$u['id']]) === 1, 'appareils toujours connectés');
    check(is_string($r['claim'] ?? null), '« Ce n\'est pas moi » proposé');
});

test('Suppression de compte', function () {
    \SimpleCommerce\Services\Demo::ensure();
    $R = \SimpleCommerce\Repo::class;
    $D = \SimpleCommerce\Services\AccountDeletion::class;
    $tag = bin2hex(random_bytes(4));
    $a = $R::createUser("a-$tag@test.invalid", 'Alice', 'mot-de-passe-1');
    $b = $R::createUser("b-$tag@test.invalid", 'Bruno', 'mot-de-passe-2');
    $cfg = ['slug' => '', 'name' => '', 'public_url' => '', 'connector' => 'catalogue', 'config' => []];
    $seul = $R::createSite(['slug' => "seul-$tag", 'name' => 'Seul'] + $cfg, $a['id']);
    $partage = $R::createSite(['slug' => "partage-$tag", 'name' => 'Partagé'] + $cfg, $a['id']);
    $R::addMember($partage['id'], $b['id'], 'editor');
    $plan = array_column(array_map(fn ($p) => [$p['site']['name'], $p['fate']], $D::plan($a['id'])), 1, 0);
    check(($plan['Seul'] ?? '') === 'retire' && ($plan['Partagé'] ?? '') === 'transmis', 'ce qui arrivera à chaque site est annoncé');
    $D::delete($a);
    check($R::user($a['id']) === null, 'compte effacé');
    check($R::site($seul['id']) === null, 'site sans autre membre retiré du portail');
    check(($R::membership($partage['id'], $b['id'])['role'] ?? '') === 'owner', 'site partagé transmis au membre restant');
    check($D::problem(['is_admin' => 1]) !== null || (int) \SimpleCommerce\Db::value('SELECT COUNT(*) FROM sc_users WHERE is_admin = 1') > 1, 'le seul administrateur ne peut pas se supprimer');
    $D::delete($R::user($b['id']));
    check($R::site($partage['id']) === null, 'nettoyage');
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone) AppleWebKit/605.1.15 SimpleCommerceApp/1.0';
    check(\SimpleCommerce\Http\Req::inApp(), "application iPhone reconnue");
    $_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone) AppleWebKit/605.1.15 Safari/604.1';
    check(!\SimpleCommerce\Http\Req::inApp(), 'Safari ordinaire non concerné');
    unset($_SERVER['HTTP_USER_AGENT']);
});

test('onglet ajouté au menu du site', function () {
    $T = \SimpleCommerce\Content\HtmlTab::class;
    $html = "<html><head><title>Accueil</title></head><body><header><nav><ul>\n  <li><a href=\"index.html\" class=\"active\">Accueil</a></li>\n  <li><a href=\"sacs.html\">Sacs</a></li>\n  <li><a href=\"contact.html\" class=\"btn\">Contact</a></li>\n</ul></nav></header>"
        . "<main><h1>Bienvenue</h1></main><footer><ul><li><a href=\"a.html\">A</a></li><li><a href=\"b.html\">B</a></li><li><a href=\"c.html\">C</a></li></ul></footer></body></html>";
    $r = $T::add($html, 'rdv', 'rendez-vous.html', 'Rendez-vous');
    check($r['added'] === 1, 'un seul menu complété (pas le bas de page)');
    check(str_contains($r['html'], "<li data-sc-onglet=\"rdv\"><a href=\"rendez-vous.html\">Rendez-vous</a></li>\n  <li><a href=\"contact.html\""), 'onglet placé avant Contact, sans la marque « page en cours »');
    check($T::remove($r['html'], 'rdv') === $html, 'onglet retiré sans trace');
    check($T::add($r['html'], 'rdv', 'rendez-vous.html', 'Rendez-vous')['html'] === $r['html'], 'pas de doublon si on recommence');
    $page = $T::page($r['html'], 'Prendre rendez-vous', '<p>RDV</p>', 'rendez-vous.html', 'index.html');
    check(str_contains($page, '<p>RDV</p>') && !str_contains($page, 'Bienvenue') && str_contains($page, 'Sacs') && $T::isOurs($page), 'page créée avec le même menu');
});

test('Choix des fiches (tailles, parfums)', function () {
    $card = fn ($n) => '<article class="cake"><img src="a.jpg" alt="' . $n . '"><h3>' . $n . '</h3><span class="price">45&nbsp;€</span>'
        . '<div class="opt-group" role="group" aria-label="Nombre de personnes"><button class="opt is-active" data-price="45" data-serves="6 à 8 pers." data-pay="https://buy.stripe.com/a">6-8 pers · 45 €</button>'
        . '<button class="opt" data-price="65" data-serves="10 à 12 pers." data-pay="https://buy.stripe.com/b">10-12 pers · 65 €</button></div>'
        . '<div class="opt-group" role="group" aria-label="Parvé ou halavi"><button class="opt is-active" data-kind="Parvé">Parvé</button><button class="opt" data-kind="Halavi">Halavi</button></div></article>';
    $html = '<main>' . $card('Fraisier') . $card('Tarte') . '</main>';
    $p = Formats::parse($html, 'html');
    $k = array_key_first($p['data']);
    check(($p['data'][$k][0]['choix_nombre_de_personnes'] ?? '') === "6-8 pers · 45 €\n10-12 pers · 65 €", 'choix lus, un par ligne');
    $d = $p['data'];
    $d[$k][0]['choix_nombre_de_personnes'] = "6-8 pers · 45 €\n10-12 pers · 65 €\n14-16 pers · 85 €";
    $d[$k][0]['choix_parve_ou_halavi'] = 'Halavi';
    $out = Formats::serialize($p, $d);
    check(str_contains($out, 'data-price="85" data-serves="14-16 pers" data-pay="#">14-16 pers · 85 €</button>'), 'choix ajouté, sans le lien de paiement copié');
    check(str_contains($out, 'data-pay="https://buy.stripe.com/b">10-12 pers'), 'choix existant gardé tel quel');
    check(!str_contains(substr($out, 0, strpos($out, 'Tarte')), 'data-kind="Parvé"') && str_contains($out, 'is-active opt" data-kind="Halavi"'), 'choix retiré, le premier reste sélectionné');
    check(substr_count($out, 'data-kind="Parvé"') === 1, 'autre fiche inchangée');
});

test('Choix des produits du catalogue (tailles, boîtes)', function () {
    $O = \SimpleCommerce\Services\ProductOptions::class;
    $p = ['nom' => 'Cookies', 'prix' => 30, 'options' => [
        ['nom' => 'Taille', 'type' => 'un', 'choix' => "Boîte de 10\nBoîte de 20 · 60 €"],
        ['nom' => 'Parfums', 'type' => 'plusieurs', 'nombre' => 4, 'choix' => "Praliné\n3 chocolats · +0,50 €"],
        ['nom' => 'Bougie', 'type' => 'un', 'choix' => "Sans\nAvec · +2 €"],
    ]];
    check($O::parseLine('40 cm · 55 €') === ['nom' => '40 cm', 'prix' => 55.0, 'ajout' => false] && $O::parseLine('Bougie · +2,50 €')['ajout'] && $O::parseLine('Vanille')['prix'] === null, 'ligne de choix lue avec son prix');
    $r = $O::resolve($p, [1, [2, 2], 1]);
    check($r['ok'] && $r['prix'] === 63.0, 'prix recalculé : taille qui remplace, boîte et ajout qui s\'ajoutent');
    check(str_contains($r['detail'], 'Parfums : 2 × Praliné, 2 × 3 chocolats'), 'détail de la boîte');
    check(!$O::resolve($p, [0, [1, 1], 0])['ok'], 'boîte incomplète refusée');
    check(!$O::resolve($p, [7, [4, 0], 0])['ok'], 'choix inexistant refusé');
    check($O::resolve(['nom' => 'Vase', 'prix' => 12], [])['prix'] === 12.0, 'produit sans choix inchangé');
});

echo $passed . ' vérifications réussies' . ($failed ? ', ' . count($failed) . " en échec :\n - " . implode("\n - ", $failed) : '') . "\n";
exit($failed ? 1 : 0);
