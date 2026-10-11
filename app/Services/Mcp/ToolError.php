<?php

declare(strict_types=1);

namespace App\Services\Mcp;

/** Fachlicher Fehler eines Tools – die Meldung geht als isError-Ergebnis an Claude, damit es nachfragen oder korrigieren kann */
final class ToolError extends \RuntimeException
{
}
