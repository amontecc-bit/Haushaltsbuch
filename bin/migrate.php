<?php

declare(strict_types=1);

/**
 * Datenbank anlegen und Migrationen ausführen:  php bin/migrate.php
 * Auf dem Webhoster alternativ über /setup (legt DB-Tabellen beim ersten Aufruf an).
 */

use App\Services\Migrator;

require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $applied = Migrator::run(true);
    echo $applied ? 'Ausgeführt: ' . implode(', ', $applied) . PHP_EOL : 'Datenbank ist aktuell.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Fehler: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
