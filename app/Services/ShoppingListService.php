<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\PurchaseRepository;
use App\Repositories\ShoppingRepository;

/**
 * Einkaufsliste: Abgleich offener Listeneinträge mit einem Einkauf.
 *
 * Ein Eintrag gilt als gekauft, wenn ein Posten des Einkaufs zu einem der echten Produkte seines virtuellen
 * Postens gehört – oder, falls (noch) nicht zugeordnet, wenn der normalisierte Postenname exakt dem Namen des
 * virtuellen Postens entspricht. Im zweiten Fall wird das Produkt dem virtuellen Posten zugeordnet (Lernen).
 */
final class ShoppingListService
{
    /** Einheiten für Listeneinträge (Schlüssel = gespeicherter Wert, '' = Stück) */
    public const UNITS = ['' => 'Stück', 'kg' => 'kg', 'g' => 'g', 'l' => 'l', 'Pck' => 'Pck.'];

    /**
     * @param array<int, array{id:int, normalized:string, product_ids:int[]}> $entries offene Listeneinträge
     * @param array<int, array{product_id:?int, name:string}> $items Posten des Einkaufs
     * @return array<int, array{product_id:?int, learn:bool}> Eintrag-ID => erkanntes Produkt; learn = neue Zuordnung
     */
    public static function match(array $entries, array $items): array
    {
        $byProduct = [];
        $byName = [];
        foreach ($items as $i) {
            $pid = !empty($i['product_id']) ? (int) $i['product_id'] : null;
            if ($pid) {
                $byProduct[$pid] = true;
            }
            $norm = CategorizationService::normalizeProduct((string) $i['name']);
            if ($norm !== '' && !isset($byName[$norm])) {
                $byName[$norm] = $pid ?? 0;
            }
        }

        $out = [];
        foreach ($entries as $e) {
            foreach ($e['product_ids'] as $pid) {
                if (isset($byProduct[(int) $pid])) {
                    $out[(int) $e['id']] = ['product_id' => (int) $pid, 'learn' => false];
                    continue 2;
                }
            }
            $norm = (string) $e['normalized'];
            if ($norm !== '' && isset($byName[$norm])) {
                $pid = $byName[$norm] ?: null;
                $out[(int) $e['id']] = ['product_id' => $pid, 'learn' => $pid !== null];
            }
        }
        return $out;
    }

    /**
     * Einkauf mit offenen Listeneinträgen des Haushalts abgleichen und Treffer abhaken.
     * Ohne $listId (automatisch nach dem Speichern eines Einkaufs) zählen nur Einträge, die spätestens am
     * Einkaufstag auf die Liste kamen – nachträglich erfasste alte Bons haken keine neue Liste ab.
     * @return int Anzahl abgehakter Einträge
     */
    public static function matchPurchase(int $householdId, int $purchaseId, string $purchaseDate, ?int $listId = null): int
    {
        $repo = new ShoppingRepository();
        $entries = $repo->openEntriesForMatching($householdId, $listId, $listId ? null : $purchaseDate);
        if (!$entries) {
            return 0;
        }
        $items = (new PurchaseRepository())->items($purchaseId);
        $hits = self::match($entries, $items);
        foreach ($hits as $entryId => $hit) {
            $repo->markDone($entryId, $purchaseId);
            if ($hit['learn']) {
                $itemId = (int) $entries[$entryId]['shopping_item_id'];
                $repo->linkProduct($itemId, (int) $hit['product_id']);
            }
        }
        return count($hits);
    }

    /** Menge für die Anzeige: „2“, „1,5 kg“, „3 Pck.“ */
    public static function quantityLabel(string|float|int $quantity, ?string $unit): string
    {
        $q = rtrim(rtrim(number_format((float) $quantity, 3, ',', '.'), '0'), ',');
        return $unit ? $q . ' ' . (self::UNITS[$unit] ?? $unit) : $q;
    }
}
