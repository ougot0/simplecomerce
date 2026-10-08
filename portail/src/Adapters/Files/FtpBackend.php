<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters\Files;

use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Adapters\RetryableConflict;

/**
 * FTP / FTPS chez n'importe quel hébergeur (si le SFTP n'est pas proposé, comme sur certaines offres d'entrée de gamme).
 * FTPS (chiffré) par défaut ; le FTP non chiffré reste possible mais déconseillé.
 */
final class FtpBackend implements FileBackend
{
    /** @var \FTP\Connection|null */
    private $conn = null;

    public function __construct(private string $host, private int $port, private string $username, private string $password, private string $tls, private string $remoteRoot)
    {
        $this->remoteRoot = '/' . trim($remoteRoot, '/');
    }

    private function abs(string $rel): string
    {
        if (str_contains($rel, '..')) {
            throw new \InvalidArgumentException('Chemin interdit');
        }
        return rtrim($this->remoteRoot, '/') . ($rel === '' ? '' : '/' . ltrim($rel, '/'));
    }

    private function connect()
    {
        if ($this->conn) {
            return $this->conn;
        }
        if (!function_exists('ftp_connect')) {
            throw new AdapterError('unsupported', "L'extension FTP de PHP n'est pas disponible sur ce serveur.");
        }
        $port = $this->port ?: 21;
        $conn = $this->tls === 'none' ? @ftp_connect($this->host, $port, 20) : @ftp_ssl_connect($this->host, $port, 20);
        if (!$conn) {
            throw new AdapterError('network', "Impossible de joindre le serveur. Vérifiez l'adresse et le port.");
        }
        if (!@ftp_login($conn, $this->username, $this->password)) {
            throw new AdapterError('auth', $this->tls === 'none'
                ? 'Identifiant ou mot de passe refusé par le serveur.'
                : 'Connexion refusée : identifiant ou mot de passe incorrect, ou le serveur n\'accepte pas la connexion sécurisée (FTPS). Essayez SFTP si votre hébergeur le propose.');
        }
        ftp_pasv($conn, true);
        return $this->conn = $conn;
    }

    public function test(): array
    {
        try {
            $conn = $this->connect();
        } catch (AdapterError $e) {
            return [['label' => "Connexion à {$this->host}", 'ok' => false, 'hint' => $e->userMessage]];
        }
        $checks = [['label' => "Connexion à {$this->host}", 'ok' => true]];
        if ($this->tls === 'none') {
            $checks[] = ['label' => 'Connexion non chiffrée', 'ok' => true, 'hint' => 'Le mot de passe circule en clair. Préférez SFTP ou FTPS si possible.'];
        }
        if (@ftp_chdir($conn, $this->remoteRoot) === false) {
            $checks[] = ['label' => 'Dossier du site', 'ok' => false, 'hint' => "Le dossier « {$this->remoteRoot} » est introuvable."];
            return $checks;
        }
        $checks[] = ['label' => 'Dossier du site trouvé', 'ok' => true];
        $probe = $this->abs('.simplecommerce-test-' . bin2hex(random_bytes(3)));
        $ok = $this->putString($probe, 'test') && @ftp_delete($conn, $probe);
        $checks[] = $ok ? ['label' => "Droit d'écriture", 'ok' => true] : ['label' => "Droit d'écriture", 'ok' => false, 'hint' => 'Le compte peut lire mais pas écrire dans ce dossier.'];
        return $checks;
    }

    private function putString(string $remote, string $content): bool
    {
        $h = fopen('php://temp', 'w+');
        fwrite($h, $content);
        rewind($h);
        $ok = @ftp_fput($this->connect(), $remote, $h, FTP_BINARY);
        fclose($h);
        return $ok;
    }

    public function read(string $path): ?array
    {
        $conn = $this->connect();
        $h = fopen('php://temp', 'w+');
        if (!@ftp_fget($conn, $h, $this->abs($path), FTP_BINARY)) {
            fclose($h);
            return null;
        }
        rewind($h);
        $content = (string) stream_get_contents($h);
        fclose($h);
        return ['content' => $content, 'version' => hash('sha256', $content)];
    }

    public function list(string $dir, int $maxDepth = 3): array
    {
        $conn = $this->connect();
        $out = [];
        $walk = function (string $rel, int $depth) use (&$walk, &$out, $conn, $maxDepth) {
            $items = @ftp_mlsd($conn, $this->abs($rel));
            if (!is_array($items)) {
                // Serveurs sans MLSD : on se contente de la liste des noms.
                $names = @ftp_nlist($conn, $this->abs($rel)) ?: [];
                foreach ($names as $n) {
                    $name = basename($n);
                    if ($name[0] !== '.') {
                        $out[] = $rel === '' ? $name : "$rel/$name";
                    }
                }
                return;
            }
            foreach ($items as $item) {
                $name = $item['name'];
                if ($name === '' || $name[0] === '.' || $name === 'node_modules') {
                    continue;
                }
                $child = $rel === '' ? $name : "$rel/$name";
                if ($item['type'] === 'dir') {
                    if ($depth < $maxDepth) {
                        $walk($child, $depth + 1);
                    }
                } elseif ($item['type'] === 'file') {
                    $out[] = $child;
                }
            }
        };
        $walk($dir, 1);
        return $out;
    }

    private function mkdirs(string $dir): void
    {
        $conn = $this->connect();
        $cur = '';
        foreach (array_filter(explode('/', $dir)) as $part) {
            $cur .= '/' . $part;
            if (@ftp_chdir($conn, $cur) === false) {
                @ftp_mkdir($conn, $cur);
            }
        }
        @ftp_chdir($conn, '/');
    }

    public function commit(array $writes, string $message, array $expected): string
    {
        $conn = $this->connect();
        foreach ($expected as $path => $version) {
            if (($this->read($path)['version'] ?? null) !== $version) {
                throw new RetryableConflict($path);
            }
        }
        foreach ($writes as $w) {
            $full = $this->abs($w['path']);
            if ($w['content'] === null) {
                @ftp_delete($conn, $full);
                continue;
            }
            $this->mkdirs(dirname($full));
            $tmp = dirname($full) . '/.' . basename($full) . '.sc-' . bin2hex(random_bytes(4));
            if (!$this->putString($tmp, $w['content'])) {
                throw new AdapterError('network', "L'écriture sur le serveur a échoué (espace disque ou droits).", $full);
            }
            // Remplacement direct si le serveur le permet, sinon en deux temps.
            if (!@ftp_rename($conn, $tmp, $full)) {
                @ftp_delete($conn, $full);
                if (!@ftp_rename($conn, $tmp, $full)) {
                    @ftp_delete($conn, $tmp);
                    throw new AdapterError('network', "L'écriture sur le serveur a échoué.", $full);
                }
            }
            // Lisible par le serveur web, quel que soit le masque de création de l'hébergeur (ignoré si non pris en charge).
            @ftp_chmod($conn, 0644, $full);
        }
        return bin2hex(random_bytes(4));
    }

    public function close(): void
    {
        if ($this->conn) {
            @ftp_close($this->conn);
            $this->conn = null;
        }
    }
}
