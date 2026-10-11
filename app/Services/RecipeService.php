<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\InventoryRepository;
use App\Repositories\RecipeRepository;
use App\Repositories\ShoppingRepository;

/**
 * Rezepte gegen den Vorrat prüfen: Was ist da, was fehlt, welche Rezepte lassen sich (fast) kochen.
 * Alle Mengen intern in Referenzeinheiten der virtuellen Posten (siehe InventoryService).
 */
final class RecipeService
{
    private const EPS = 0.0005;

    /**
     * Zutat in Referenzeinheiten: passt die Einheit nicht zur Referenz (sollte das Formular verhindern),
     * zählt die Menge direkt als Anzahl Referenzmengen.
     */
    public static function needRef(array $ing, float $scale): float
    {
        $qty = (float) $ing['quantity'] * $scale;
        return InventoryService::toRef($qty, $ing['unit'] ?? null, (float) $ing['ref_quantity'], $ing['ref_unit'] ?? null) ?? $qty;
    }

    /**
     * Rezept mit dem Vorrat abgleichen.
     * @param array<int, array{shopping_item_id:int, quantity:float|string, unit:?string, ref_quantity:float|string, ref_unit:?string, staple:int|bool}> $ingredients
     * @param array<int, float> $stock Bestand je Posten-ID in Referenzeinheiten
     * @param float $scale gewünschte Portionen / Portionen des Rezepts
     * @return array{ingredients: array, complete: bool, missing_count: int, total: int, coverage: float}
     *         je Zutat zusätzlich need/have/missing (have = davon gedeckt) und stock (gesamter Bestand des Postens)
     */
    public static function evaluate(array $ingredients, array $stock, float $scale = 1.0): array
    {
        $left = $stock; // derselbe Posten kann mehrfach vorkommen und teilt sich den Bestand
        $out = [];
        $missingCount = 0;
        $coverage = 0.0;
        foreach ($ingredients as $ing) {
            $itemId = (int) $ing['shopping_item_id'];
            $need = round(self::needRef($ing, $scale), 3);
            if (!empty($ing['staple'])) {
                $have = $need;
            } else {
                $have = round(min($need, max(0.0, $left[$itemId] ?? 0.0)), 3);
                $left[$itemId] = ($left[$itemId] ?? 0.0) - $have;
            }
            $missing = round(max(0.0, $need - $have), 3);
            if ($missing > self::EPS) {
                $missingCount++;
            }
            $coverage += $need > self::EPS ? $have / $need : 1.0;
            $out[] = $ing + [
                'need' => $need, 'have' => $have, 'missing' => $missing > self::EPS ? $missing : 0.0,
                'stock' => round(max(0.0, $stock[$itemId] ?? 0.0), 3),
            ];
        }
        $total = count($out);
        return [
            'ingredients'   => $out,
            'complete'      => $missingCount === 0,
            'missing_count' => $missingCount,
            'total'         => $total,
            'coverage'      => $total ? round($coverage / $total, 3) : 1.0,
        ];
    }

    /**
     * Vorschläge sortieren: zuerst vollständig machbar, dann wenige fehlende Zutaten, dann hohe Abdeckung, dann Name.
     * @param array<int, array{name:string, eval:array}> $recipes
     */
    public static function rank(array $recipes): array
    {
        usort($recipes, fn ($a, $b) =>
            [$b['eval']['complete'], $a['eval']['missing_count'], $b['eval']['coverage'], mb_strtolower($a['name'])]
            <=> [$a['eval']['complete'], $b['eval']['missing_count'], $a['eval']['coverage'], mb_strtolower($b['name'])]);
        return $recipes;
    }

    /** Skalierungsfaktor aus gewünschten und Rezept-Portionen */
    public static function scale(int $servings, int $baseServings): float
    {
        return $baseServings > 0 && $servings > 0 ? $servings / $baseServings : 1.0;
    }

    /** Darstellung eines Rezepts mit Vorratsabgleich (für Vorschläge und Detailansicht) */
    public static function card(array $r, array $eval, ?int $servings): array
    {
        $servings ??= (int) $r['servings'];
        $scale = self::scale($servings, (int) $r['servings']);
        return [
            'id'            => (int) $r['id'],
            'name'          => $r['name'],
            'servings'      => $servings,
            'complete'      => $eval['complete'],
            'missing_count' => $eval['missing_count'],
            'total'         => $eval['total'],
            'coverage'      => (int) round($eval['coverage'] * 100),
            'ingredients'   => array_map(function ($i) use ($scale) {
                $refQty = (float) $i['ref_quantity'];
                $unit = $i['unit'] ?: null;
                $missingIn = $i['missing'] > 0 ? InventoryService::fromRef($i['missing'], $unit, $refQty, $i['ref_unit']) : null;
                return [
                    'item_id' => (int) $i['shopping_item_id'],
                    'name'    => $i['name'],
                    'note'    => (string) $i['note'],
                    'staple'  => (bool) $i['staple'],
                    'need'    => ShoppingListService::quantityLabel(round((float) $i['quantity'] * $scale, 3), $unit),
                    'have'    => InventoryService::label($i['stock'], $refQty, $i['ref_unit']),
                    'missing' => $i['missing'] > 0
                        ? ($missingIn !== null ? ShoppingListService::quantityLabel(round($missingIn, 3), $unit) : InventoryService::label($i['missing'], $refQty, $i['ref_unit']))
                        : '',
                ];
            }, $eval['ingredients']),
        ];
    }

    // ---- Datenbank -------------------------------------------------------------------

    /** @return array<int, array> Rezept-ID => Ergebnis von evaluate() (null Portionen = wie im Rezept) */
    public static function evaluateAll(int $householdId, array $recipes, ?int $servings): array
    {
        $ids = array_map(fn ($r) => (int) $r['id'], $recipes);
        $ingredients = (new RecipeRepository())->ingredients($ids);
        $stock = (new InventoryRepository())->stockMap($householdId, date('Y-m-d'));
        $out = [];
        foreach ($recipes as $r) {
            $scale = $servings ? self::scale($servings, (int) $r['servings']) : 1.0;
            $out[(int) $r['id']] = self::evaluate($ingredients[(int) $r['id']] ?? [], $stock, $scale);
        }
        return $out;
    }

    /**
     * Zutaten aus Eingabezeilen: item_id (vorhandener virtueller Posten) oder name (wird gesucht/angelegt),
     * Menge, Einheit passend zur Referenz des Postens, Notiz. Leere Zeilen werden übersprungen.
     * @return array<int, array{shopping_item_id:int, quantity:float, unit:?string, note:?string}>
     * @throws \DomainException Meldung für den Benutzer (Einheit passt nicht zum Posten)
     */
    public static function ingredientRows(int $householdId, array $rows): array
    {
        $shop = new ShoppingRepository();
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $qty = (float) str_replace(',', '.', trim((string) ($row['quantity'] ?? '')));
            $unit = InventoryService::unit((string) ($row['unit'] ?? ''));
            $item = !empty($row['item_id']) ? $shop->findItem((int) $row['item_id'], $householdId) : null;
            if (!$item) {
                $itemName = mb_substr(trim((string) ($row['name'] ?? '')), 0, 190);
                if ($itemName === '') {
                    continue;
                }
                $existed = $shop->findItemByNormalized($householdId, mb_substr(ShoppingListService::normalize($itemName), 0, 190));
                $item = $shop->findItem(ShoppingListService::itemByName($householdId, $itemName), $householdId);
                if (!$existed && $unit !== null) {
                    // Neuer Posten: Referenz passend zur Zutat (1 kg bzw. 1 l)
                    $ref = in_array($unit, ['g', 'kg'], true) ? 'kg' : 'l';
                    $shop->updateItem((int) $item['id'], $householdId, ['ref_quantity' => 1, 'ref_unit' => $ref]);
                    $item['ref_unit'] = $ref;
                }
            }
            if ($qty <= 0) {
                $qty = 1.0;
            }
            if (!$item['staple'] && !InventoryService::compatible($unit, $item['ref_unit'])) {
                $ref = InventoryService::unit($item['ref_unit']);
                throw new \DomainException("„{$item['name']}“ wird in " . ($ref ? "$ref (bzw. " . (in_array($ref, ['g', 'kg'], true) ? 'g/kg' : 'ml/l') . ')' : 'Stück')
                    . ' geführt – bitte eine passende Einheit wählen oder die Referenz beim virtuellen Posten ändern.');
            }
            $out[] = [
                'shopping_item_id' => (int) $item['id'], 'quantity' => min(round($qty, 3), 99999), 'unit' => $unit,
                'note' => mb_substr(trim((string) ($row['note'] ?? '')), 0, 190) ?: null,
            ];
        }
        return $out;
    }

    /**
     * Gekocht: Zutaten (skaliert auf die Portionen) vom Vorrat abbuchen, Grundvorrat ausgenommen.
     * @return string[] Zutaten, von denen laut Vorrat nicht genug da war
     */
    public static function cook(int $householdId, array $recipe, int $servings): array
    {
        $scale = self::scale($servings, (int) $recipe['servings']);
        $short = [];
        foreach ((new RecipeRepository())->ingredients([(int) $recipe['id']])[(int) $recipe['id']] ?? [] as $ing) {
            if ($ing['staple']) {
                continue;
            }
            $rest = InventoryService::consume($householdId, (int) $ing['shopping_item_id'], self::needRef($ing, $scale));
            if ($rest > 0) {
                $short[] = $ing['name'];
            }
        }
        InventoryService::refreshAutoList($householdId);
        return $short;
    }

    /**
     * Fehlende Zutaten (auf ganze Referenzmengen aufgerundet) auf eine Einkaufsliste setzen.
     * @return string[] Namen der eingetragenen Posten
     */
    public static function addMissingToList(int $householdId, array $recipe, int $servings, int $listId): array
    {
        $shop = new ShoppingRepository();
        $eval = self::evaluateAll($householdId, [$recipe], $servings)[(int) $recipe['id']];
        $added = [];
        foreach ($eval['ingredients'] as $ing) {
            if ($ing['missing'] <= 0) {
                continue;
            }
            [$qty, $unit] = InventoryService::shoppingQuantity($ing['missing'], (float) $ing['ref_quantity'], $ing['ref_unit']);
            $shop->addEntry($listId, (int) $ing['shopping_item_id'], $qty, $unit, mb_substr('für ' . $recipe['name'], 0, 190));
            $added[] = $ing['name'];
        }
        return $added;
    }
}
