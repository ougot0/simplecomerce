<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters\Files;

use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Net\SFTP;
use SimpleCommerce\Adapters\AdapterError;
use SimpleCommerce\Adapters\RetryableConflict;

/**
 * SFTP chez n'importe quel hébergeur (OVH, o2switch, Hostinger, Infomaniak, IONOS, serveur dédié…),
 * en PHP pur (phpseclib) : aucune extension serveur nécessaire.
 * Écriture atomique : fichier temporaire puis renommage, pour que le site ne lise jamais un fichier à moitié écrit.
 * L'empreinte du serveur est mémorisée à la première connexion et vérifiée ensuite.
 */
final class SftpBackend implements FileBackend
{
    private ?SFTP $sftp = null;
    public ?string $seenFingerprint = null;

    public function __construct(
        private string $host,
        private int $port,
        private string $username,
        private ?string $password,
        private ?string $privateKey,
        private ?string $passphrase,
        private string $remoteRoot,
        private ?string $hostFingerprint = null,
    ) {
        $this->remoteRoot = '/' . trim($remoteRoot, '/');
    }

    private function abs(string $rel): string
    {
        if (str_contains($rel, '..')) {
            throw new \InvalidArgumentException('Chemin interdit');
        }
        return rtrim($this->remoteRoot, '/') . ($rel === '' ? '' : '/' . ltrim($rel, '/'));
    }

    private function connect(): SFTP
    {
        if ($this->sftp) {
            return $this->sftp;
        }
        $sftp = new SFTP($this->host, $this->port ?: 22, 15);
        try {
            $key = $sftp->getServerPublicHostKey();
        } catch (\Throwable $e) {
            throw new AdapterError('network', 'Impossible de joindre le serveur. Vérifiez l\'adresse et le port.', $e->getMessage());
        }
        if ($key === false) {
            throw new AdapterError('network', 'Impossible de joindre le serveur. Vérifiez l\'adresse et le port.');
        }
        $parts = explode(' ', $key);
        $this->seenFingerprint = 'SHA256:' . rtrim(base64_encode(hash('sha256', base64_decode($parts[1] ?? ''), true)), '=');
        if ($this->hostFingerprint && $this->hostFingerprint !== $this->seenFingerprint) {
            throw new AdapterError('auth', "L'identité du serveur a changé depuis la dernière connexion. Par sécurité, la connexion est bloquée : contactez votre administrateur.");
        }
        try {
            $credential = $this->privateKey ? PublicKeyLoader::load($this->privateKey, $this->passphrase ?: false) : (string) $this->password;
            $ok = $sftp->login($this->username, $credential);
        } catch (\Throwable $e) {
            throw new AdapterError('auth', 'La clé privée est illisible ou protégée par une autre phrase de passe.', $e->getMessage());
        }
        if (!$ok) {
            throw new AdapterError('auth', 'Identifiant ou mot de passe refusé par le serveur.');
        }
        return $this->sftp = $sftp;
    }

    public function test(): array
    {
        try {
            $sftp = $this->connect();
        } catch (AdapterError $e) {
            return [['label' => "Connexion à {$this->host}", 'ok' => false, 'hint' => $e->userMessage]];
        }
        $checks = [['label' => "Connexion à {$this->host}", 'ok' => true]];
        if (!$sftp->is_dir($this->remoteRoot)) {
            $checks[] = ['label' => 'Dossier du site', 'ok' => false, 'hint' => "Le dossier « {$this->remoteRoot} » n'existe pas sur le serveur (souvent « /www » chez OVH, « /public_html » ailleurs)."];
            return $checks;
        }
        $checks[] = ['label' => 'Dossier du site trouvé', 'ok' => true];
        $probe = $this->abs('.simplecommerce-test-' . bin2hex(random_bytes(3)));
        $checks[] = ($sftp->put($probe, 'test') && $sftp->delete($probe))
            ? ['label' => "Droit d'écriture", 'ok' => true]
            : ['label' => "Droit d'écriture", 'ok' => false, 'hint' => 'Le compte peut lire mais pas écrire dans ce dossier.'];
        return $checks;
    }

    public function read(string $path): ?array
    {
        $sftp = $this->connect();
        $full = $this->abs($path);
        if (!$sftp->is_file($full)) {
            return null;
        }
        $content = $sftp->get($full);
        if ($content === false) {
            throw new AdapterError('network', 'Lecture impossible sur le serveur.', $full);
        }
        return ['content' => $content, 'version' => hash('sha256', $content)];
    }

    public function list(string $dir, int $maxDepth = 3): array
    {
        $sftp = $this->connect();
        $out = [];
        $walk = function (string $rel, int $depth) use (&$walk, &$out, $sftp, $maxDepth) {
            $items = $sftp->rawlist($this->abs($rel));
            if (!is_array($items)) {
                return;
            }
            foreach ($items as $name => $attr) {
                $name = (string) $name;
                if ($name === '' || $name[0] === '.' || $name === 'node_modules') {
                    continue;
                }
                $child = $rel === '' ? $name : "$rel/$name";
                if (($attr['type'] ?? 0) === NET_SFTP_TYPE_DIRECTORY) {
                    if ($depth < $maxDepth) {
                        $walk($child, $depth + 1);
                    }
                } elseif (($attr['type'] ?? 0) === NET_SFTP_TYPE_REGULAR) {
                    $out[] = $child;
                }
            }
        };
        $walk($dir, 1);
        return $out;
    }

    public function commit(array $writes, string $message, array $expected): string
    {
        $sftp = $this->connect();
        foreach ($expected as $path => $version) {
            if (($this->read($path)['version'] ?? null) !== $version) {
                throw new RetryableConflict($path);
            }
        }
        foreach ($writes as $w) {
            $full = $this->abs($w['path']);
            if ($w['content'] === null) {
                $sftp->delete($full);
                continue;
            }
            $dir = dirname($full);
            if (!$sftp->is_dir($dir)) {
                $sftp->mkdir($dir, -1, true);
            }
            $tmp = $dir . '/.' . basename($full) . '.sc-' . bin2hex(random_bytes(4));
            if (!$sftp->put($tmp, $w['content'])) {
                throw new AdapterError('network', "L'écriture sur le serveur a échoué (espace disque ou droits).", $full);
            }
            // Renommage avec remplacement si le serveur le permet, sinon en deux temps.
            if (!$sftp->rename($tmp, $full)) {
                $sftp->delete($full);
                if (!$sftp->rename($tmp, $full)) {
                    $sftp->delete($tmp);
                    throw new AdapterError('network', "L'écriture sur le serveur a échoué.", $full);
                }
            }
            // Lisible par le serveur web, quel que soit le masque de création de l'hébergeur.
            $sftp->chmod(0644, $full);
        }
        return bin2hex(random_bytes(4));
    }

    public function close(): void
    {
        $this->sftp?->disconnect();
        $this->sftp = null;
    }
}
