<?php
declare(strict_types=1);

namespace SimpleCommerce\Adapters\Files;

/**
 * Accès aux fichiers d'un site, où qu'ils soient : dépôt Git, serveur SFTP/FTP de n'importe quel hébergeur, dossier local.
 * Les chemins sont relatifs à la racine configurée et déjà vérifiés.
 */
interface FileBackend
{
    /** @return array<int, array{label: string, ok: bool, hint?: string}> */
    public function test(): array;

    /** @return array{content: string, version: string}|null */
    public function read(string $path): ?array;

    /** @return string[] fichiers sous $dir (chemins relatifs à la racine) */
    public function list(string $dir, int $maxDepth = 3): array;

    /**
     * Écrit tout d'un coup (un seul commit pour les dépôts). $expected : version lue de chaque fichier
     * (null = ne doit pas exister). Si un fichier a changé entre-temps : RetryableConflict.
     * @param array<int, array{path: string, content: ?string}> $writes
     */
    public function commit(array $writes, string $message, array $expected): string;

    public function close(): void;
}
