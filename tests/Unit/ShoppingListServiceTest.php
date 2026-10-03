<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ShoppingListService;
use PHPUnit\Framework\TestCase;

final class ShoppingListServiceTest extends TestCase
{
    private function entry(int $id, string $normalized, array $productIds = []): array
    {
        return ['id' => $id, 'shopping_item_id' => $id * 10, 'normalized' => $normalized, 'product_ids' => $productIds];
    }

    public function testMatchesAnyLinkedProduct(): void
    {
        // Virtueller Posten „Milch“ mit zwei Produkten aus verschiedenen Läden – gekauft wurde das zweite
        $entries = [1 => $this->entry(1, 'milch', [5, 7]), 2 => $this->entry(2, 'butter', [9])];
        $items = [['product_id' => 7, 'name' => 'ja! Fettarme Milch 1,5%'], ['product_id' => 3, 'name' => 'Bananen']];
        self::assertSame([1 => ['product_id' => 7, 'learn' => false]], ShoppingListService::match($entries, $items));
    }

    public function testMatchesByNormalizedNameAndLearnsProduct(): void
    {
        $entries = [4 => $this->entry(4, 'butter')];
        $items = [['product_id' => 12, 'name' => 'Butter 250g']];
        self::assertSame([4 => ['product_id' => 12, 'learn' => true]], ShoppingListService::match($entries, $items));
    }

    public function testNameMatchWithoutProductDoesNotLearn(): void
    {
        $entries = [4 => $this->entry(4, 'butter')];
        $items = [['product_id' => null, 'name' => 'BUTTER']];
        self::assertSame([4 => ['product_id' => null, 'learn' => false]], ShoppingListService::match($entries, $items));
    }

    public function testNoFuzzyNameMatch(): void
    {
        // „Milch“ steckt in „Milchschokolade“, ist aber nicht dasselbe – ohne Zuordnung kein Treffer
        $entries = [1 => $this->entry(1, 'milch')];
        $items = [['product_id' => 20, 'name' => 'Milchschokolade'], ['product_id' => 21, 'name' => 'Fettarme Milch']];
        self::assertSame([], ShoppingListService::match($entries, $items));
    }

    public function testLinkedProductWinsOverName(): void
    {
        $entries = [1 => $this->entry(1, 'milch', [21])];
        $items = [['product_id' => 30, 'name' => 'Milch'], ['product_id' => 21, 'name' => 'Fettarme Milch']];
        self::assertSame([1 => ['product_id' => 21, 'learn' => false]], ShoppingListService::match($entries, $items));
    }

    public function testQuantityLabel(): void
    {
        self::assertSame('2', ShoppingListService::quantityLabel('2.000', null));
        self::assertSame('1,5 kg', ShoppingListService::quantityLabel(1.5, 'kg'));
        self::assertSame('10 Pck.', ShoppingListService::quantityLabel('10.000', 'Pck'));
        self::assertSame('250 g', ShoppingListService::quantityLabel(250, 'g'));
    }
}
