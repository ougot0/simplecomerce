<?php
declare(strict_types=1);

namespace SimpleCommerce;

use PDO;

/** Accès à la base : MySQL/MariaDB chez l'hébergeur, SQLite pour la démonstration. */
final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo) {
            return self::$pdo;
        }
        self::$pdo = self::connect((array) Config::get('db', []));
        return self::$pdo;
    }

    /** Se connecte à une base précise (installation). */
    public static function use(array $cfg): void
    {
        self::$pdo = self::connect($cfg);
    }

    public static function connect(array $cfg): PDO
    {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if (($cfg['driver'] ?? 'mysql') === 'sqlite') {
            $pdo = new PDO('sqlite:' . $cfg['path'], null, null, $options);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
            return $pdo;
        }
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $cfg['host'] ?? 'localhost', (int) ($cfg['port'] ?? 3306), $cfg['name'] ?? '');
        return new PDO($dsn, (string) ($cfg['user'] ?? ''), (string) ($cfg['password'] ?? ''), $options);
    }

    public static function driver(): string
    {
        return self::pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /** @return array<int, array<string, mixed>> */
    public static function all(string $sql, array $params = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public static function one(string $sql, array $params = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $row = $st->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        $v = $st->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function run(string $sql, array $params = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    /** INSERT à partir d'un tableau colonne => valeur. */
    public static function insert(string $table, array $row): void
    {
        $cols = array_keys($row);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table, implode(', ', $cols), implode(', ', array_map(fn ($c) => ':' . $c, $cols)));
        self::run($sql, array_combine(array_map(fn ($c) => ':' . $c, $cols), array_values($row)));
    }

    public static function update(string $table, array $row, string $where, array $params): void
    {
        $sets = implode(', ', array_map(fn ($c) => "$c = :set_$c", array_keys($row)));
        $bind = [];
        foreach ($row as $c => $v) {
            $bind[":set_$c"] = $v;
        }
        self::run("UPDATE $table SET $sets WHERE $where", $bind + $params);
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public static function now(): string
    {
        return gmdate('Y-m-d\TH:i:s\Z');
    }

    public static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
