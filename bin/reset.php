<?php

declare(strict_types=1);

/**
 * Datenbank komplett leeren und neu aufsetzen:  php bin/reset.php [--yes] [--keep-uploads]
 *
 * Löscht ALLE Tabellen der konfigurierten Datenbank (DB_NAME aus .env), führt danach die
 * Migrationen neu aus und leert storage/uploads (Belege, Import-Dateien, Temp-Dateien).
 * Anschließend führt /setup durch die Ersteinrichtung.
 *
 *   --yes           ohne Rückfrage ausführen
 *   --keep-uploads  hochgeladene Dateien behalten
 */

use App\Core\Config;
use App\Core\Database;
use App\Services\Migrator;

require dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Nur über die Kommandozeile.');
}

$args = array_slice($argv, 1);
$force = in_array('--yes', $args, true);
$keepUploads = in_array('--keep-uploads', $args, true);
$dbName = (string) Config::get('db.name');

if (!$force) {
    echo "ACHTUNG: Alle Daten in der Datenbank \"{$dbName}\" werden unwiderruflich gelöscht" .
        ($keepUploads ? '.' : ', ebenso alle hochgeladenen Belege.') . PHP_EOL;
    echo "Zur Bestätigung den Datenbanknamen eingeben: ";
    $input = trim((string) fgets(STDIN));
    if ($input !== $dbName) {
        echo 'Abgebrochen.' . PHP_EOL;
        exit(1);
    }
}

try {
    $db = Database::connection();

    // Alle Tabellen löschen (statt DROP DATABASE – funktioniert auch ohne entsprechende Rechte beim Webhoster)
    $tables = $db->query('SHOW FULL TABLES WHERE Table_type = \'BASE TABLE\'')->fetchAll(PDO::FETCH_COLUMN);
    $views = $db->query('SHOW FULL TABLES WHERE Table_type = \'VIEW\'')->fetchAll(PDO::FETCH_COLUMN);
    $db->exec('SET FOREIGN_KEY_CHECKS = 0');
    foreach ($views as $view) {
        $db->exec('DROP VIEW IF EXISTS `' . str_replace('`', '``', $view) . '`');
    }
    foreach ($tables as $table) {
        $db->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
    }
    $db->exec('SET FOREIGN_KEY_CHECKS = 1');
    echo 'Gelöscht: ' . count($tables) . ' Tabellen' . ($views ? ', ' . count($views) . ' Views' : '') . PHP_EOL;

    $applied = Migrator::run();
    echo 'Migrationen ausgeführt: ' . implode(', ', $applied) . PHP_EOL;

    if (!$keepUploads) {
        $count = clearDirectory((string) Config::get('paths.uploads'));
        echo "Uploads gelöscht: {$count} Dateien" . PHP_EOL;
    }

    echo 'Fertig. Die Ersteinrichtung erfolgt beim nächsten Aufruf über /setup.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

/** Löscht den Inhalt eines Verzeichnisses rekursiv; Unterordner der ersten Ebene und .gitkeep bleiben erhalten */
function clearDirectory(string $dir, int $depth = 0): int
{
    $count = 0;
    foreach (new DirectoryIterator($dir) as $item) {
        if ($item->isDot() || $item->getFilename() === '.gitkeep') {
            continue;
        }
        $path = $item->getPathname();
        if ($item->isDir()) {
            $count += clearDirectory($path, $depth + 1);
            if ($depth > 0) {
                rmdir($path);
            }
        } else {
            unlink($path);
            $count++;
        }
    }
    return $count;
}
