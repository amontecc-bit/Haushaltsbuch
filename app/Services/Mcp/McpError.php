<?php

declare(strict_types=1);

namespace App\Services\Mcp;

/** Protokollfehler (JSON-RPC-Fehlercode als Code), z. B. unbekannte Methode oder unbekanntes Tool */
final class McpError extends \RuntimeException
{
}
