<?php
declare(strict_types=1);

namespace SimpleCommerce\Controllers;

use SimpleCommerce\Adapters\Registry;
use SimpleCommerce\Config;
use SimpleCommerce\Content\Formats;
use SimpleCommerce\Http\Halt;
use SimpleCommerce\Http\Req;
use SimpleCommerce\Http\Response;
use SimpleCommerce\Repo;
use SimpleCommerce\Services\Access;
use SimpleCommerce\Services\Media;
use SimpleCommerce\Services\Scheduler;

final class MiscController
{
    public static function help(): Response
    {
        $v = Access::viewer();
        return Response::page('help', ['viewer' => $v, 'title' => 'Aide'], $v ? 'plain' : 'auth');
    }

    /** Photo en attente : visible seulement par les personnes du site concerné. */
    public static function media(array $p): Response
    {
        $v = Access::requireViewer();
        $m = Repo::media($p['id']);
        if (!$m || (!Access::isAdmin($v) && !Repo::membership($m['site_id'], $v['effective']['id']))) {
            throw Halt::notFound();
        }
        $bytes = Media::bytes($m);
        if ($bytes === null) {
            throw Halt::notFound();
        }
        return Response::file($bytes, $m['mime']);
    }

    /** Tâche planifiée OVH : https://…/cron?cle=… toutes les 5 ou 10 minutes. */
    public static function cron(): Response
    {
        $key = (string) Config::get('cron_key', '');
        if ($key === '' || !hash_equals($key, Req::query('cle', 200))) {
            throw Halt::notFound();
        }
        return new Response('ok ' . Scheduler::run() . "\n", 200, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    // ------------------------------------------------------------ sites de démonstration

    private static function esc(mixed $s): string
    {
        return htmlspecialchars(is_scalar($s) ? (string) $s : '', ENT_QUOTES, 'UTF-8');
    }

    /** Markdown très simple (paragraphes, gras, italique) pour la démonstration. */
    private static function md(string $text): string
    {
        $out = '';
        foreach (preg_split('/\n\s*\n/', trim($text)) as $para) {
            $h = self::esc(trim($para));
            $h = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $h);
            $h = preg_replace('/(?<![*\w])\*(?!\s)(.+?)\*/s', '<em>$1</em>', $h);
            $out .= '<p>' . nl2br($h) . '</p>';
        }
        return $out;
    }

    private static function page(string $title, string $base, string $css, string $body): string
    {
        return '<!doctype html><html lang="fr"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . self::esc($title)
            . '</title><link rel="stylesheet" href="' . $base . '/style.css"></head><body>' . str_replace('src="/', 'src="' . $base . '/', $body) . '</body></html>';
    }

    private static function lune(string $root): array
    {
        $c = json_decode((string) file_get_contents("$root/content.json"), true) ?: [];
        $items = '';
        foreach ($c['gateaux'] ?? [] as $g) {
            $off = ($g['disponible'] ?? true) === false;
            $items .= '<article class="item' . ($off ? ' off' : '') . '">' . (!empty($g['photo']) ? '<img src="' . self::esc($g['photo']) . '" alt="">' : '')
                . '<h3>' . self::esc($g['nom'] ?? '') . '</h3><div class="price">' . self::esc($g['prix'] ?? '') . ($off ? ' — victime de son succès' : '') . '</div><p>' . self::esc($g['description'] ?? '') . '</p></article>';
        }
        $news = '';
        foreach ($c['actualites'] ?? [] as $a) {
            $news .= '<h3>' . self::esc($a['titre'] ?? '') . '</h3><p>' . self::esc($a['texte'] ?? '') . '</p>';
        }
        $hours = '';
        foreach ($c['infos']['horaires'] ?? [] as $h) {
            $hours .= '<tr><td>' . self::esc($h['jour'] ?? '') . '</td><td>' . self::esc($h['heures'] ?? '') . '</td></tr>';
        }
        $a = $c['accueil'] ?? [];
        $body = (!empty($a['message']) ? '<div class="msg">' . self::esc($a['message']) . '</div>' : '')
            . '<header><h1>' . self::esc($a['titre'] ?? '') . '</h1><p>' . self::esc($a['accroche'] ?? '') . '</p></header>'
            . '<section class="grid">' . $items . '</section><section class="news"><h2>Actualités</h2>' . $news . '</section>'
            . '<section class="infos"><h2>Infos pratiques</h2><p>' . self::esc($c['infos']['adresse'] ?? '') . ' · ' . self::esc($c['infos']['telephone'] ?? '') . '</p><table>' . $hours . '</table></section>';
        return [$a['titre'] ?? 'Pâtisserie Lune', $body];
    }

    private static function brun(string $root): array
    {
        $site = Formats::parse((string) file_get_contents("$root/content/site.yml"), 'yaml')['data'] ?? [];
        $projets = [];
        foreach (glob("$root/content/projets/*.md") ?: [] as $f) {
            $projets[] = Formats::parse((string) file_get_contents($f), 'markdown');
        }
        usort($projets, fn ($a, $b) => ((int) ($a['data']['ordre'] ?? 99)) <=> ((int) ($b['data']['ordre'] ?? 99)));
        $body = '<header><h1>' . self::esc($site['nom'] ?? '') . '</h1><p>' . self::esc($site['presentation'] ?? '') . '</p></header>';
        foreach ($projets as $p) {
            $d = $p['data'];
            $body .= '<article class="p">' . (!empty($d['photo']) ? '<img src="' . self::esc($d['photo']) . '" alt="">' : '<div></div>') . '<div><h2>' . self::esc($d['titre'] ?? '')
                . '</h2><div class="meta">' . self::esc($d['lieu'] ?? '') . ' · ' . self::esc($d['annee'] ?? '') . ' · ' . self::esc($d['surface'] ?? '') . '</div>' . self::md((string) $p['body']) . '</div></article>';
        }
        $body .= '<footer>' . self::esc($site['adresse'] ?? '') . ' · ' . self::esc($site['telephone'] ?? '') . ' · ' . self::esc($site['email'] ?? '') . '</footer>';
        return [$site['nom'] ?? 'Atelier Brun', $body];
    }

    private const CSS = [
        'patisserie-lune' => 'body{margin:0;font-family:Georgia,serif;background:#fbf7f0;color:#2b2118}header{padding:48px 6vw 24px}h1{font-size:clamp(40px,7vw,80px);margin:0;font-weight:400;letter-spacing:-.02em}.msg{background:#2b2118;color:#fbf7f0;padding:10px 6vw;font-family:system-ui}.grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(240px,1fr));gap:32px;padding:24px 6vw}.item img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block}.item h3{margin:12px 0 4px;font-size:22px;font-weight:400}.price{font-family:system-ui;font-weight:700}.off{opacity:.45}.news,.infos{padding:24px 6vw}h2{font-weight:400;font-size:30px;border-top:1px solid #2b2118;padding-top:16px}table{border-collapse:collapse}td{padding:4px 24px 4px 0;font-family:system-ui}',
        'atelier-brun' => 'body{margin:0;font-family:"Helvetica Neue",Arial,sans-serif;background:#f4f4f1;color:#151515}header{padding:40px 5vw;display:grid;grid-template-columns:1fr 1fr;gap:24px;border-bottom:1px solid #151515}h1{margin:0;font-size:28px;text-transform:uppercase;letter-spacing:.04em}.p{display:grid;grid-template-columns:2fr 1fr;gap:32px;padding:32px 5vw;border-bottom:1px solid #ccc}.p img{width:100%;display:block}.p h2{margin:0 0 8px;font-weight:500}.meta{color:#666}footer{padding:32px 5vw}',
        'ferme' => 'body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:Georgia,serif;background:#2b2118;color:#fbf7f0;padding:24px;box-sizing:border-box}main{max-width:36ch}h1{font-weight:400;font-size:clamp(34px,6vw,56px);margin:0 0 16px}p{font-size:20px;line-height:1.5}',
    ];

    /** Les deux « sites clients » de la démonstration, relus à chaque visite comme le ferait leur hébergeur. */
    public static function demoSite(array $p): Response
    {
        $name = $p['site'];
        if (!Config::demo() || !isset(self::CSS[$name]) || $name === 'ferme') {
            throw Halt::notFound();
        }
        $root = Registry::demoRoot() . '/' . $name;
        $base = "/demo-sites/$name";
        $rest = $p['rest'] ?? '';
        if ($rest === '/style.css') {
            $closed = self::closed($root);
            return new Response(self::CSS[$closed ? 'ferme' : $name], 200, ['Content-Type' => 'text/css; charset=utf-8', 'Cache-Control' => 'no-store']);
        }
        if ($rest === '' || $rest === '/') {
            $closed = self::closed($root);
            if ($closed) {
                $title = $name === 'patisserie-lune' ? 'Pâtisserie Lune' : 'Atelier Brun architectes';
                $date = $closed['reouverture'] ? \SimpleCommerce\Support\Text::date($closed['reouverture'], false, true) : null;
                $html = self::page($title, $base, '', '<main><h1>' . self::esc($title) . '</h1><p>' . self::esc($closed['message']) . '</p>' . ($date ? '<p>Réouverture le ' . self::esc($date) . '.</p>' : '') . '</main>');
            } else {
                [$title, $body] = $name === 'patisserie-lune' ? self::lune($root) : self::brun($root);
                $html = self::page($title, $base, '', $body);
            }
            header_remove('Content-Security-Policy');
            header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; frame-ancestors 'self'");
            return Response::html($html);
        }
        $rel = ltrim($rest, '/');
        if (!preg_match('/^[\w\-\/]+\.(webp|jpe?g|png)$/i', $rel) || str_contains($rel, '..')) {
            throw Halt::notFound();
        }
        foreach (["$root/public/$rel", "$root/$rel"] as $file) {
            if (is_file($file)) {
                $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                return Response::file((string) file_get_contents($file), $ext === 'webp' ? 'image/webp' : ($ext === 'png' ? 'image/png' : 'image/jpeg'), null, 'no-store');
            }
        }
        throw Halt::notFound();
    }

    private static function closed(string $root): ?array
    {
        foreach (['simplecommerce-statut.json', 'content/simplecommerce-statut.json', 'public/simplecommerce-statut.json'] as $rel) {
            if (is_file("$root/$rel")) {
                $s = json_decode((string) file_get_contents("$root/$rel"), true);
                return !empty($s['ferme']) ? ['message' => (string) ($s['message'] ?? ''), 'reouverture' => $s['reouverture'] ?? null] : null;
            }
        }
        return null;
    }
}
