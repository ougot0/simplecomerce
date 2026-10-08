<?php
declare(strict_types=1);

namespace SimpleCommerce\Services;

use SimpleCommerce\Adapters\PendingAsset;
use SimpleCommerce\Db;
use SimpleCommerce\Repo;
use SimpleCommerce\Support\Text;

/**
 * Photos : le navigateur recadre et compresse ; le serveur revérifie tout.
 * Vrai type vérifié par le contenu (pas l'extension) : JPEG, PNG, WebP. SVG refusé.
 * Taille et nombre de pixels limités ; réencodage en WebP, qui retire les métadonnées (position GPS des téléphones).
 * En attente d'enregistrement, les photos sont gardées dans storage/media (hors du dossier public).
 */
final class Media
{
    public const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_PIXELS = 40_000_000;

    public static function process(string $bytes, string $originalName, int $maxWidth = 2000): array
    {
        if ($bytes === '') {
            throw new \InvalidArgumentException('Le fichier est vide.');
        }
        if (strlen($bytes) > self::MAX_BYTES) {
            throw new \InvalidArgumentException('La photo est trop lourde (8 Mo maximum).');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            throw new \InvalidArgumentException('Format non accepté. Envoyez une photo JPEG, PNG ou WebP.');
        }
        $info = @getimagesizefromstring($bytes);
        if (!$info || $info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new \InvalidArgumentException('Cette image est illisible ou trop grande.');
        }
        $img = @imagecreatefromstring($bytes);
        if (!$img) {
            throw new \InvalidArgumentException('Cette image est illisible.');
        }
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data('data://image/jpeg;base64,' . base64_encode($bytes));
            $img = match ((int) ($exif['Orientation'] ?? 1)) {
                3 => imagerotate($img, 180, 0),
                6 => imagerotate($img, -90, 0),
                8 => imagerotate($img, 90, 0),
                default => $img,
            };
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $maxWidth = max(200, min($maxWidth, 4000));
        if ($w > $maxWidth) {
            $nh = (int) round($h * $maxWidth / $w);
            $resized = imagecreatetruecolor($maxWidth, $nh);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagecopyresampled($resized, $img, 0, 0, 0, 0, $maxWidth, $nh, $w, $h);
            imagedestroy($img);
            $img = $resized;
            [$w, $h] = [$maxWidth, $nh];
        }
        ob_start();
        imagewebp($img, null, 82);
        $out = (string) ob_get_clean();
        imagedestroy($img);
        $base = substr(Text::slugify(preg_replace('/\.[^.]+$/', '', $originalName)), 0, 40) ?: 'photo';
        return ['bytes' => $out, 'width' => $w, 'height' => $h, 'fileName' => $base . '-' . substr(bin2hex(random_bytes(4)), 0, 8) . '.webp'];
    }

    private static function dir(string $siteId): string
    {
        $dir = SC_STORAGE . '/media/' . preg_replace('/[^a-f0-9-]/', '', $siteId);
        if (!is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        return $dir;
    }

    public static function stage(string $siteId, string $userId, string $bytes, string $originalName, string $alt, int $maxWidth): array
    {
        $p = self::process($bytes, $originalName, $maxWidth);
        $id = Db::uuid();
        $path = preg_replace('/[^a-f0-9-]/', '', $siteId) . "/$id.webp";
        file_put_contents(self::dir($siteId) . "/$id.webp", $p['bytes']);
        return Repo::insertMedia(['id' => $id, 'site_id' => $siteId, 'uploaded_by' => $userId, 'path' => $path, 'file_name' => $p['fileName'], 'mime' => 'image/webp',
            'bytes' => strlen($p['bytes']), 'width' => $p['width'], 'height' => $p['height'], 'alt' => mb_substr($alt, 0, 300)]);
    }

    public static function bytes(array $media): ?string
    {
        $file = SC_STORAGE . '/media/' . $media['path'];
        return is_file($file) ? (string) file_get_contents($file) : null;
    }

    /** Charge les photos en attente référencées — uniquement celles de ce site. @return PendingAsset[] */
    public static function load(string $siteId, array $tokens): array
    {
        $out = [];
        foreach ($tokens as $token) {
            if (!preg_match('/^[0-9a-f-]{36}$/', $token)) {
                continue;
            }
            $m = Repo::media($token);
            if (!$m || $m['site_id'] !== $siteId || ($bytes = self::bytes($m)) === null) {
                continue;
            }
            $out[] = new PendingAsset($token, $bytes, $m['file_name'], $m['mime'], $m['alt']);
        }
        return $out;
    }
}
