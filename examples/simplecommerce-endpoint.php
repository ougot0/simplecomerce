<?php
/**
 * Point d'accès Simple Commerce minimal, en un fichier.
 * Stocke le contenu dans content.json (même dossier) et les photos dans uploads/.
 *
 * Installation : copier ce fichier sur le site (ex. /simplecommerce/index.php),
 * définir la clé secrète ci-dessous (longue et aléatoire), puis dans le portail :
 * « Mon site a sa propre base de données » → adresse https://www.mon-site.fr/simplecommerce + la clé.
 * Avec Apache, ajouter un .htaccess : RewriteEngine On / RewriteRule ^ index.php [L]
 */

const SECRET = 'REMPLACEZ-PAR-UNE-CLE-LONGUE-ET-ALEATOIRE';
const CONTENT_FILE = __DIR__ . '/content.json';
const UPLOAD_DIR = __DIR__ . '/../uploads';
const UPLOAD_URL = '/uploads';

// Le schéma décrit ce que le client peut modifier.
$SCHEMA = [
  'version' => 1,
  'sections' => [
    ['key' => 'produits', 'label' => 'Produits', 'kind' => 'collection', 'itemLabel' => 'produit',
     'titleField' => 'nom', 'imageField' => 'photo', 'subtitleField' => 'prix', 'idField' => 'id', 'orderable' => true,
     'fields' => [
       ['key' => 'nom', 'label' => 'Nom', 'type' => 'text', 'required' => true, 'maxLength' => 80],
       ['key' => 'photo', 'label' => 'Photo', 'type' => 'image', 'aspect' => '4:3'],
       ['key' => 'prix', 'label' => 'Prix', 'type' => 'price', 'priceFormat' => ['store' => 'number']],
       ['key' => 'description', 'label' => 'Description', 'type' => 'textarea', 'maxLength' => 500],
     ]],
    ['key' => 'infos', 'label' => 'Infos pratiques', 'kind' => 'singleton',
     'fields' => [
       ['key' => 'telephone', 'label' => 'Téléphone', 'type' => 'phone'],
       ['key' => 'horaires', 'label' => 'Horaires', 'type' => 'textarea'],
     ]],
  ],
];

header('Content-Type: application/json; charset=utf-8');

function reply($status, $body) { http_response_code($status); echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }

$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (!hash_equals('Bearer ' . SECRET, $auth) || SECRET === 'REMPLACEZ-PAR-UNE-CLE-LONGUE-ET-ALEATOIRE') reply(401, ['error' => 'unauthorized']);

$path = trim(preg_replace('#^.*/simplecommerce#', '', parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
$parts = $path === '' ? [] : explode('/', $path);
$method = $_SERVER['REQUEST_METHOD'];
$body = json_decode(file_get_contents('php://input') ?: 'null', true) ?? [];

function sectionDef($key) { global $SCHEMA; foreach ($SCHEMA['sections'] as $s) if ($s['key'] === $key) return $s; reply(404, ['error' => 'section']); }
function pick($section, $data) { $out = []; foreach ($section['fields'] as $f) if (array_key_exists($f['key'], $data)) $out[$f['key']] = $data[$f['key']]; return $out; }

// Lecture/écriture du fichier sous verrou exclusif.
function withContent($fn) {
  $fp = fopen(CONTENT_FILE, 'c+'); flock($fp, LOCK_EX);
  $raw = stream_get_contents($fp); $content = $raw ? json_decode($raw, true) : [];
  $result = $fn($content);
  ftruncate($fp, 0); rewind($fp);
  fwrite($fp, json_encode($content, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
  fflush($fp); flock($fp, LOCK_UN); fclose($fp);
  return $result;
}
function sameAs($section, $current, $expected) { return $expected === null || pick($section, $current) == pick($section, $expected); }

if ($parts === ['ping']) reply(200, ['ok' => true, 'name' => $_SERVER['HTTP_HOST']]);
if ($parts === ['schema']) reply(200, $SCHEMA);

// Fermeture temporaire : le site doit lire content.json → _statut et afficher le message si « ferme » vaut true.
if ($parts === ['status']) {
  if ($method === 'GET') { $c = json_decode(@file_get_contents(CONTENT_FILE) ?: '{}', true); reply(200, $c['_statut'] ?? ['ferme' => false, 'message' => '', 'reouverture' => null]); }
  if ($method === 'PUT') reply(200, withContent(function (&$c) use ($body) {
    $c['_statut'] = ['ferme' => !empty($body['ferme']), 'message' => (string)($body['message'] ?? ''), 'reouverture' => $body['reouverture'] ?? null];
    return $c['_statut'];
  }));
}

if ($parts === ['media'] && $method === 'POST') {
  $f = $_FILES['file'] ?? null;
  if (!$f || $f['size'] > 6 * 1024 * 1024 || !in_array(mime_content_type($f['tmp_name']), ['image/webp', 'image/jpeg', 'image/png'], true)) reply(400, ['error' => 'file']);
  @mkdir(UPLOAD_DIR, 0755, true);
  $name = bin2hex(random_bytes(6)) . '-' . preg_replace('/[^a-z0-9.\-]/', '', strtolower(basename($f['name'])));
  move_uploaded_file($f['tmp_name'], UPLOAD_DIR . '/' . $name);
  reply(200, ['url' => UPLOAD_URL . '/' . $name]);
}

if (($parts[0] ?? '') !== 'sections' || !isset($parts[1])) reply(404, ['error' => 'not found']);
$section = sectionDef($parts[1]);
$key = $section['key'];

if (count($parts) === 2 && $section['kind'] === 'singleton') {
  if ($method === 'GET') { $c = json_decode(@file_get_contents(CONTENT_FILE) ?: '{}', true); reply(200, ['data' => pick($section, $c[$key] ?? [])]); }
  if ($method === 'PUT') reply(200, withContent(function (&$c) use ($section, $key, $body) {
    $cur = $c[$key] ?? [];
    if (!sameAs($section, $cur, $body['expected'] ?? null)) reply(409, ['error' => 'conflict']);
    $c[$key] = array_merge($cur, pick($section, $body['data'] ?? []));
    return ['data' => pick($section, $c[$key])];
  }));
}

if (count($parts) === 2 && $method === 'GET') {
  $c = json_decode(@file_get_contents(CONTENT_FILE) ?: '{}', true);
  reply(200, ['entries' => array_map(fn($i) => ['id' => (string)$i['id'], 'data' => pick($section, $i)], $c[$key] ?? [])]);
}

if (count($parts) === 3 && $parts[2] === 'order' && $method === 'PUT') reply(200, withContent(function (&$c) use ($key, $body) {
  $byId = []; foreach ($c[$key] ?? [] as $i) $byId[(string)$i['id']] = $i;
  $ids = $body['ids'] ?? [];
  if (count($ids) !== count($byId)) reply(409, ['error' => 'conflict']);
  $c[$key] = array_map(fn($id) => $byId[$id] ?? reply(409, ['error' => 'conflict']), $ids);
  return ['ok' => true];
}));

if (count($parts) === 3 && $parts[2] === 'entries' && $method === 'POST') reply(200, withContent(function (&$c) use ($section, $key, $body) {
  $list = $c[$key] ?? [];
  $id = $list ? max(array_map(fn($i) => (int)$i['id'], $list)) + 1 : 1;
  $item = ['id' => $id] + pick($section, $body['data'] ?? []);
  $c[$key][] = $item;
  return ['id' => (string)$id, 'data' => pick($section, $item)];
}));

if (count($parts) === 4 && $parts[2] === 'entries') {
  $id = $parts[3];
  if ($method === 'GET') {
    $c = json_decode(@file_get_contents(CONTENT_FILE) ?: '{}', true);
    foreach ($c[$key] ?? [] as $i) if ((string)$i['id'] === $id) reply(200, ['id' => $id, 'data' => pick($section, $i)]);
    reply(404, ['error' => 'not found']);
  }
  reply(200, withContent(function (&$c) use ($section, $key, $id, $method, $body) {
    foreach ($c[$key] ?? [] as $n => $i) {
      if ((string)$i['id'] !== $id) continue;
      if (!sameAs($section, $i, $body['expected'] ?? null)) reply(409, ['error' => 'conflict']);
      if ($method === 'DELETE') { array_splice($c[$key], $n, 1); return ['ok' => true]; }
      $c[$key][$n] = array_merge($i, pick($section, $body['data'] ?? []));
      return ['data' => pick($section, $c[$key][$n])];
    }
    reply(404, ['error' => 'not found']);
  }));
}

reply(404, ['error' => 'not found']);
