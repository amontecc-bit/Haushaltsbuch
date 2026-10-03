<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\RecipeService;
use PHPUnit\Framework\TestCase;

final class RecipeServiceTest extends TestCase
{
    private function ing(int $item, float $qty, ?string $unit, float $refQty = 1, ?string $refUnit = null, bool $staple = false): array
    {
        return ['shopping_item_id' => $item, 'quantity' => $qty, 'unit' => $unit, 'ref_quantity' => $refQty, 'ref_unit' => $refUnit, 'staple' => $staple];
    }

    public function testEvaluateCompleteRecipe(): void
    {
        // Pfannkuchen: 250 g Mehl (Ref. 1 kg), 0,5 l Milch (Ref. 1 l), 3 Eier, Salz (Grundvorrat)
        $ings = [$this->ing(1, 250, 'g', 1, 'kg'), $this->ing(2, 0.5, 'l', 1, 'l'), $this->ing(3, 3, null), $this->ing(4, 1, null, 1, null, true)];
        $r = RecipeService::evaluate($ings, [1 => 1.0, 2 => 1.0, 3 => 6.0]);
        self::assertTrue($r['complete']);
        self::assertSame(0, $r['missing_count']);
        self::assertSame(4, $r['total']);
        self::assertSame(1.0, $r['coverage']);
        self::assertSame(0.25, $r['ingredients'][0]['need']);
    }

    public function testEvaluateScalesAndReportsMissing(): void
    {
        $ings = [$this->ing(1, 250, 'g', 1, 'kg'), $this->ing(2, 0.5, 'l', 1, 'l'), $this->ing(3, 3, null)];
        // doppelte Portionen: 0,5 kg Mehl, 1 l Milch, 6 Eier – nur 0,6 l Milch und 4 Eier da
        $r = RecipeService::evaluate($ings, [1 => 1.0, 2 => 0.6, 3 => 4.0], RecipeService::scale(4, 2));
        self::assertFalse($r['complete']);
        self::assertSame(2, $r['missing_count']);
        self::assertSame(0.4, $r['ingredients'][1]['missing']);
        self::assertSame(2.0, $r['ingredients'][2]['missing']);
        self::assertSame(round((1 + 0.6 + 4 / 6) / 3, 3), $r['coverage']);
    }

    public function testSameItemTwiceSharesStock(): void
    {
        // Butter für Teig und Füllung (je 125 g, Ref. 250 g), nur 1 Packung = 250 g da … und 50 g zu wenig bei 300 g Bedarf
        $ings = [$this->ing(5, 125, 'g', 250, 'g'), $this->ing(5, 175, 'g', 250, 'g')];
        $r = RecipeService::evaluate($ings, [5 => 1.0]);
        self::assertSame(0.0, $r['ingredients'][0]['missing']);
        self::assertSame(0.2, $r['ingredients'][1]['missing']);
        self::assertSame(1, $r['missing_count']);
    }

    public function testRank(): void
    {
        $mk = fn ($name, $complete, $missing, $cov) => ['name' => $name, 'eval' => ['complete' => $complete, 'missing_count' => $missing, 'coverage' => $cov]];
        $ranked = RecipeService::rank([
            $mk('Suppe', false, 2, 0.8), $mk('Auflauf', false, 1, 0.5), $mk('Brot', true, 0, 1.0),
            $mk('Salat', false, 1, 0.9), $mk('Apfelmus', true, 0, 1.0),
        ]);
        self::assertSame(['Apfelmus', 'Brot', 'Salat', 'Auflauf', 'Suppe'], array_column($ranked, 'name'));
    }

    public function testScale(): void
    {
        self::assertSame(2.0, RecipeService::scale(4, 2));
        self::assertSame(1.0, RecipeService::scale(0, 2));
    }
}
