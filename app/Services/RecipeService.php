<?php

declare(strict_types=1);

namespace App\Services;

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
}
