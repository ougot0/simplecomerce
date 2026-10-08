<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters\Files;

use SimpleCommerce\Adapters\RetryableConflict;

/** Dossier local : utilisé par la démonstration. */
final class LocalBackend implements FileBackend
{
    public function __construct(private string $root)
    {
        $this->root = rtrim($root, '/');
    }

    private function abs(string $rel): string
    {
        $full = $this->root . ($rel === '' ? '' : '/' . $rel);
        if (str_contains($rel, '..')) {
            throw new \InvalidArgumentException('Chemin interdit');
        }
        return $full;
    }

    public function test(): array
    {
        return [is_dir($this->root) ? ['label' => 'Dossier du site accessible', 'ok' => true] : ['label' => 'Dossier du site accessible', 'ok' => false, 'hint' => 'Dossier introuvable.']];
    }

    public function read(string $path): ?array
    {
        $full = $this->abs($path);
        if (!is_file($full)) {
            return null;
        }
        $content = (string) file_get_contents($full);
        return ['content' => $content, 'version' => hash('sha256', $content)];
    }

    public function list(string $dir, int $maxDepth = 3): array
    {
        $out = [];
        $walk = function (string $rel, int $depth) use (&$walk, &$out, $maxDepth) {
            $full = $this->abs($rel);
            if (!is_dir($full)) {
                return;
            }
            foreach (scandir($full) ?: [] as $name) {
                if ($name[0] === '.' || $name === 'node_modules') {
                    continue;
                }
                $child = $rel === '' ? $name : "$rel/$name";
                if (is_dir("$full/$name")) {
                    if ($depth < $maxDepth) {
                        $walk($child, $depth + 1);
                    }
                } else {
                    $out[] = $child;
                }
            }
        };
        $walk($dir, 1);
        return $out;
    }

    public function commit(array $writes, string $message, array $expected): string
    {
        foreach ($expected as $path => $version) {
            if (($this->read($path)['version'] ?? null) !== $version) {
                throw new RetryableConflict($path);
            }
        }
        foreach ($writes as $w) {
            $full = $this->abs($w['path']);
            if ($w['content'] === null) {
                @unlink($full);
                continue;
            }
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0775, true);
            }
            $tmp = $full . '.sc-' . bin2hex(random_bytes(4));
            file_put_contents($tmp, $w['content']);
            rename($tmp, $full);
        }
        return bin2hex(random_bytes(4));
    }

    public function close(): void
    {
    }
}
