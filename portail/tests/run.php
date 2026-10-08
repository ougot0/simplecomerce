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

echo $passed . ' vérifications réussies' . ($failed ? ', ' . count($failed) . " en échec :\n - " . implode("\n - ", $failed) : '') . "\n";
exit($failed ? 1 : 0);
