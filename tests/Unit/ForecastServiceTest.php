<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\ForecastService;
use PHPUnit\Framework\TestCase;

final class ForecastServiceTest extends TestCase
{
    public function testProjectAppliesEventsAndVariableSpending(): void
    {
        $r = ForecastService::project(
            [1 => 1000.0, 2 => 500.0],
            [
                ['date' => '2026-10-01', 'account_id' => 1, 'amount' => 2000.0],
                ['date' => '2026-10-03', 'account_id' => 1, 'amount' => -900.0],
                ['date' => '2026-10-05', 'account_id' => 99, 'amount' => -50.0], // nicht einbezogenes Konto
            ],
            [1 => -310.0], // variable Ausgaben pro Monat
            '2026-09-30',
            '2026-10-31'
        );
        self::assertSame('2026-09-30', $r['dates'][0]);
        self::assertSame(1500.0, $r['total'][0], 'Heute ohne Veränderung');
        // Ende Oktober: 1000 + 2000 - 900 - 310 (31 Tage à 10 €) = 1790 auf Konto 1
        self::assertEqualsWithDelta(1790.0, end($r['series'][1]), 0.01);
        self::assertEqualsWithDelta(500.0, end($r['series'][2]), 0.01);
        self::assertNull($r['min']['negative_from']);
    }

    public function testNegativeDetection(): void
    {
        $r = ForecastService::project([1 => 100.0], [['date' => '2026-10-02', 'account_id' => 1, 'amount' => -150.0]], [], '2026-10-01', '2026-10-10');
        self::assertSame('2026-10-02', $r['min']['negative_from']);
        self::assertSame(-50.0, $r['min']['value']);
    }
}
