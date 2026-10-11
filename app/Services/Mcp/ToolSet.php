<?php

declare(strict_types=1);

namespace App\Services\Mcp;

use App\Repositories\CategoryRepository;
use App\Repositories\OAuthRepository;
use App\Repositories\ShoppingRepository;
use App\Services\InventoryService;
use App\Services\ShoppingListService;

/**
 * Gemeinsame Basis der MCP-Tool-Gruppen: Haushalt/Benutzer, Protokoll, Parameter lesen, Posten und Kategorien finden.
 */
abstract class ToolSet
{
    public function __construct(protected readonly int $hid, protected readonly int $userId, protected readonly ?string $clientName = null)
    {
    }

    /** @return array<string, array{title:string, description:string, inputSchema:array, annotations:array, handler:callable}> */
    abstract public function tools(): array;

    protected function log(string $tool, string $summary): void
    {
        (new OAuthRepository())->log($this->hid, $this->userId ?: null, $this->clientName, $tool, $summary);
    }

    /**
     * Virtuellen Posten per ID oder Name finden (exakt, sonst eindeutiger Teiltreffer), auf Wunsch anlegen.
     * $unitText legt bei neuen Posten die Referenz fest (g/kg → 1 kg, ml/l → 1 l).
     */
    protected function findItem(array $a, bool $create = false, string $unitText = '', string $nameKey = 'item', string $idKey = 'item_id',
                                string $hint = ' list_items zeigt alle Posten.'): array
    {
        $repo = new ShoppingRepository();
        if ($id = self::int($a, $idKey)) {
            return $repo->findItem($id, $this->hid) ?? throw new ToolError("Posten $id gibt es nicht.");
        }
        $name = self::str($a, $nameKey);
        if ($name === '') {
            throw new ToolError("Bitte $nameKey (Name) oder $idKey angeben.");
        }
        $item = $repo->findItemByNormalized($this->hid, mb_substr(ShoppingListService::normalize($name), 0, 190));
        if ($item) {
            return $item;
        }
        $similar = array_values(array_filter($repo->items($this->hid),
            fn ($i) => mb_stripos($i['name'], $name) !== false || mb_stripos($name, $i['name']) !== false));
        if (count($similar) === 1) {
            return $similar[0];
        }
        if ($similar) {
            throw new ToolError("„{$name}“ ist mehrdeutig: " . implode(', ', array_slice(array_map(
                fn ($i) => "{$i['name']} (item_id {$i['id']})", $similar), 0, 10)) . ' – bitte genauer angeben.');
        }
        if (!$create) {
            throw new ToolError("Einen Posten „{$name}“ gibt es nicht." . $hint);
        }
        $item = $repo->findItem(ShoppingListService::itemByName($this->hid, $name), $this->hid);
        $unit = $unitText !== '' ? self::stockUnit($unitText) : null;
        if ($unit !== null) {
            $ref = in_array($unit, ['g', 'kg'], true) ? 'kg' : 'l';
            $repo->updateItem((int) $item['id'], $this->hid, ['ref_quantity' => 1, 'ref_unit' => $ref]);
            $item = $repo->findItem((int) $item['id'], $this->hid);
        }
        return $item;
    }

    /** Einheit für Vorratsmengen; unbekannte Einheiten sind ein Fehler (statt still als Stück zu zählen) */
    protected static function stockUnit(string $unit): ?string
    {
        $u = InventoryService::unit($unit);
        if ($u === null && !in_array(mb_strtolower(rtrim($unit, '.')), ['stück', 'stk', 'st'], true)) {
            throw new ToolError("Einheit „{$unit}“ unbekannt – bitte g, kg, ml, l oder Stück.");
        }
        return $u;
    }

    /** Ausgaben-Kategorie per Name (exakt, sonst eindeutiger Teiltreffer im vollen Namen „Eltern › Kind“) */
    protected function categoryByName(string $name): int
    {
        $all = (new CategoryRepository())->all($this->hid, 'expense');
        $n = mb_strtolower($name);
        foreach ($all as $c) {
            if (mb_strtolower($c['name']) === $n || mb_strtolower($c['full_name']) === $n) {
                return (int) $c['id'];
            }
        }
        $hits = array_values(array_filter($all, fn ($c) => mb_stripos($c['full_name'], $name) !== false));
        if (count($hits) === 1) {
            return (int) $hits[0]['id'];
        }
        throw new ToolError("Kategorie „{$name}“ " . ($hits ? 'ist mehrdeutig' : 'gibt es nicht') . '. Ausgaben-Kategorien: '
            . implode(', ', array_slice(array_column($hits ?: $all, 'full_name'), 0, 60)) . '.');
    }

    protected static function readOnly(): array
    {
        return ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
    }

    protected static function write(bool $destructive, bool $idempotent): array
    {
        return ['readOnlyHint' => false, 'destructiveHint' => $destructive, 'idempotentHint' => $idempotent, 'openWorldHint' => false];
    }

    protected static function str(array $a, string $key, int $max = 190): string
    {
        $v = $a[$key] ?? '';
        return is_scalar($v) ? mb_substr(trim((string) $v), 0, $max) : '';
    }

    protected static function int(array $a, string $key): ?int
    {
        $v = $a[$key] ?? null;
        return is_numeric($v) ? (int) $v : null;
    }

    /** Zahl, auch als Text mit Komma („1,5“) */
    protected static function num(array $a, string $key): ?float
    {
        $v = $a[$key] ?? null;
        if (is_string($v)) {
            $v = str_replace(',', '.', trim($v));
        }
        return is_numeric($v) ? (float) $v : null;
    }

    protected static function bool(array $a, string $key, bool $default): bool
    {
        $v = $a[$key] ?? null;
        return $v === null ? $default : (filter_var($v, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default);
    }

    /** @return string[] */
    protected static function strings(array $a, string $key): array
    {
        $v = $a[$key] ?? [];
        $v = is_string($v) ? [$v] : (is_array($v) ? $v : []);
        return array_values(array_filter(array_map(fn ($s) => is_scalar($s) ? mb_substr(trim((string) $s), 0, 190) : '', $v)));
    }

    /** @return int[] */
    protected static function ints(array $a, string $key): array
    {
        $v = $a[$key] ?? [];
        $v = is_array($v) ? $v : [$v];
        return array_values(array_unique(array_filter(array_map(fn ($x) => is_numeric($x) ? (int) $x : 0, $v))));
    }
}
