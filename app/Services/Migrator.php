<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Core\Database;
use PDO;

final class Migrator
{
    /**
     * Fehler „existiert schon“ bzw. „existiert nicht“ (Tabelle, Spalte, Index, Fremdschlüssel): Die Änderung ist bereits
     * vorhanden, z. B. von Hand angelegt oder nach einem Abbruch. MySQL 8 kennt kein „ADD COLUMN IF NOT EXISTS“ (MariaDB schon),
     * daher werden Migrationen ohne IF (NOT) EXISTS geschrieben und diese Fehler hier übersprungen.
     */
    private const ALREADY_APPLIED = [1050, 1060, 1061, 1091, 1826];

    /**
     * Führt alle noch nicht angewendeten SQL-Dateien aus database/migrations aus.
     * @param bool $createDatabase Datenbank anlegen, falls sie fehlt (benötigt Rechte)
     * @return string[] Namen der ausgeführten Migrationen
     */
    public static function run(bool $createDatabase = false): array
    {
        if ($createDatabase) {
            $c = Config::get('db');
            $pdo = new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $c['host'], $c['port']), $c['user'], $c['pass'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '', $c['name']) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
        }

        $db = Database::connection();
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            name VARCHAR(190) PRIMARY KEY, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

        $done = $db->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(Config::get('paths.root') . '/database/migrations/*.sql') ?: [];
        sort($files);

        $applied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            foreach (self::splitStatements((string) file_get_contents($file)) as $sql) {
                try {
                    $db->exec($sql);
                } catch (\PDOException $e) {
                    if (!in_array((int) ($e->errorInfo[1] ?? 0), self::ALREADY_APPLIED, true)) {
                        throw new \RuntimeException("Migration $name fehlgeschlagen: " . $e->getMessage(), 0, $e);
                    }
                }
            }
            $db->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
            $applied[] = $name;
        }
        return $applied;
    }

    public static function pending(): bool
    {
        try {
            $db = Database::connection();
            $done = $db->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        } catch (\PDOException) {
            return true;
        }
        $files = array_map('basename', glob(Config::get('paths.root') . '/database/migrations/*.sql') ?: []);
        return (bool) array_diff($files, $done);
    }

    /** Einfache Aufteilung an ";" am Zeilenende, Kommentare entfernt */
    private static function splitStatements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql),
            fn ($l) => !str_starts_with(ltrim($l), '--')
        );
        $parts = preg_split('/;\s*(\R|$)/', implode("\n", $lines));
        return array_values(array_filter(array_map('trim', $parts), fn ($s) => $s !== ''));
    }
}
