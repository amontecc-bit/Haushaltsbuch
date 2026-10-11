<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\InventoryService;
use PHPUnit\Framework\TestCase;

final class InventoryServiceTest extends TestCase
{
    public function testToRefConvertsCompatibleUnits(): void
    {
        self::assertEqualsWithDelta(0.5, InventoryService::toRef(500, 'g', 1, 'kg'), 1e-9);
        self::assertEqualsWithDelta(0.25, InventoryService::toRef(250, 'ml', 1, 'l'), 1e-9);
        self::assertEqualsWithDelta(2.0, InventoryService::toRef(500, 'g', 250, 'g'), 1e-9);
        self::assertEqualsWithDelta(0.5, InventoryService::toRef(3, null, 6, null), 1e-9);  // 3 Eier bei Referenz 6er-Packung
        self::assertEqualsWithDelta(1.5, InventoryService::toRef(1.5, 'L', 1, 'l'), 1e-9);  // Großschreibung
    }

    public function testToRefRejectsIncompatibleUnits(): void
    {
        self::assertNull(InventoryService::toRef(1, 'kg', 1, 'l'));
        self::assertNull(InventoryService::toRef(2, null, 1, 'kg'));
        self::assertNull(InventoryService::toRef(200, 'g', 1, null));
    }

    public function testFromRef(): void
    {
        self::assertEqualsWithDelta(300.0, InventoryService::fromRef(0.3, 'g', 1, 'kg'), 1e-9);
        self::assertNull(InventoryService::fromRef(1, 'ml', 1, 'kg'));
    }

    public function testPurchaseAmount(): void
    {
        // 2 × Milch 0,5 l (Stück, Faktor 0,5) bei Referenz 1 l
        self::assertSame(1.0, InventoryService::purchaseAmount(2, null, 0.5, 1, 'l'));
        // lose Ware: 1,2 kg Bananen bei Referenz 1 kg
        self::assertSame(1.2, InventoryService::purchaseAmount(1.2, 'kg', 1, 1, 'kg'));
        // lose Ware, aber Referenz in Stück → Faktor
        self::assertSame(6.0, InventoryService::purchaseAmount(1.2, 'kg', 5, 1, null));
    }

    public function testGuessFactorFromProductName(): void
    {
        self::assertSame(0.5, InventoryService::guessFactor('ja! Fettarme Milch 0,5l', 1, 'l'));
        self::assertSame(1.5, InventoryService::guessFactor('Weihenst. H-Milch 1,5 L', 1, 'l'));
        self::assertSame(2.5, InventoryService::guessFactor('Mehl Type 405 2,5kg', 1, 'kg'));
        self::assertSame(0.9, InventoryService::guessFactor('Joghurt 6x150g', 1, 'kg'));
        self::assertSame(0.33, InventoryService::guessFactor('Cola 33cl', 1, 'l'));
        self::assertSame(10.0, InventoryService::guessFactor('Eier Freiland 10er', 1, null));
        self::assertNull(InventoryService::guessFactor('Bananen', 1, 'kg'));
        self::assertNull(InventoryService::guessFactor('Milch 1l', 1, 'kg'));
    }

    public function testConsumeFifoTakesLotsInOrder(): void
    {
        $lots = [['id' => 1, 'remaining' => 0.5], ['id' => 2, 'remaining' => 1], ['id' => 3, 'remaining' => 1]];
        self::assertSame(['updates' => [1 => 0.0, 2 => 0.3], 'rest' => 0.0], InventoryService::consumeFifo($lots, 1.2));
        self::assertSame(['updates' => [1 => 0.0, 2 => 0.0, 3 => 0.0], 'rest' => 0.5], InventoryService::consumeFifo($lots, 3));
    }

    public function testSyncLotsKeepsConsumption(): void
    {
        $existing = [
            ['id' => 10, 'product_id' => 7, 'quantity' => 2, 'remaining' => 1.5],   // 0,5 schon verbraucht
            ['id' => 11, 'product_id' => 8, 'quantity' => 1, 'remaining' => 1],     // Posten wurde aus dem Einkauf entfernt
        ];
        $wanted = [
            7 => ['shopping_item_id' => 3, 'quantity' => 3.0, 'expires_on' => '2026-10-10'],
            9 => ['shopping_item_id' => 4, 'quantity' => 1.0, 'expires_on' => null],
        ];
        $diff = InventoryService::syncLots($existing, $wanted);
        self::assertSame([11], $diff['delete']);
        self::assertSame(['shopping_item_id' => 3, 'quantity' => 3.0, 'remaining' => 2.5, 'expires_on' => '2026-10-10'], $diff['update'][10]);
        self::assertSame([9 => $wanted[9]], $diff['insert']);

        // Weniger gekauft als schon verbraucht → Rest 0, nicht negativ
        $diff = InventoryService::syncLots([['id' => 1, 'product_id' => 7, 'quantity' => 2, 'remaining' => 0.5]], [7 => ['shopping_item_id' => 3, 'quantity' => 1.0, 'expires_on' => null]]);
        self::assertSame(0.0, $diff['update'][1]['remaining']);
    }

    public function testStatus(): void
    {
        self::assertSame('ok', InventoryService::status(2, 0, true, false, null));
        self::assertSame('low', InventoryService::status(1, 1, true, false, null));
        self::assertSame('out', InventoryService::status(0, 0, true, false, null));
        self::assertSame('expired', InventoryService::status(0, 0, true, false, '2026-09-30'));
        self::assertSame('none', InventoryService::status(0, 0, false, false, null));
        self::assertSame('staple', InventoryService::status(0, 0, true, true, null));
        self::assertTrue(InventoryService::needsRestock('expired', true));
        self::assertFalse(InventoryService::needsRestock('out', false));
        self::assertFalse(InventoryService::needsRestock('none', true));
    }

    public function testAutoListDiff(): void
    {
        $items = [
            1 => ['needs' => true, 'tracked' => true, 'depleted_at' => '2026-10-03 08:00:00'],  // fehlt, noch nicht auf der Liste
            2 => ['needs' => true, 'tracked' => true, 'depleted_at' => '2026-10-03 08:00:00'],  // fehlt, steht schon offen drauf
            3 => ['needs' => false, 'tracked' => true, 'depleted_at' => null],                  // wieder da → austragen
            4 => ['needs' => true, 'tracked' => true, 'depleted_at' => '2026-10-03 08:00:00'],  // nach dem Ausgehen abgehakt → nicht erneut
            5 => ['needs' => true, 'tracked' => true, 'depleted_at' => '2026-10-03 08:00:00'],  // vor dem erneuten Ausgehen abgehakt → wieder drauf
            6 => ['needs' => false, 'tracked' => false, 'depleted_at' => null],                 // von Hand eingetragen, nicht geführt → bleibt
        ];
        $entries = [
            2 => ['id' => 20, 'done_at' => null],
            3 => ['id' => 30, 'done_at' => null],
            4 => ['id' => 40, 'done_at' => '2026-10-03 09:00:00'],
            5 => ['id' => 50, 'done_at' => '2026-09-20 09:00:00'],
            6 => ['id' => 60, 'done_at' => null],
        ];
        self::assertSame(['add' => [1, 5], 'remove' => [30]], InventoryService::autoListDiff($items, $entries));
    }

    public function testShoppingQuantityRoundsUpToWholeReferences(): void
    {
        self::assertSame([250.0, 'g'], InventoryService::shoppingQuantity(0.4, 250, 'g'));
        self::assertSame([2.0, 'l'], InventoryService::shoppingQuantity(1.2, 1, 'l'));
        self::assertSame([3.0, null], InventoryService::shoppingQuantity(3, 1, null));
        self::assertSame([1.0, 'kg'], InventoryService::shoppingQuantity(1.0000001, 1, 'kg'));
    }

    public function testLabel(): void
    {
        self::assertSame('1,5 l', InventoryService::label(1.5, 1, 'l'));
        self::assertSame('500 g', InventoryService::label(2, 250, 'g'));
        self::assertSame('3 Stück', InventoryService::label(3, 1, null));
    }
}
